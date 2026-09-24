<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

use App\Scanning\Heuristics\ComposerNamespaceScanner;

/**
 * What is actually installed, from lockfiles, versus what the manifests
 * declare. composer.lock carries every package's PSR-4/PSR-0 autoload map,
 * so a PHP namespace resolves to its package exactly (Inertia\ belongs to
 * inertiajs/inertia-laravel; prefix-matching package names can never get
 * that right). npm lockfiles list every installed package by name. Pure
 * data: files are parsed as JSON/text and never evaluated.
 */
final class DependencyIndex
{
    public const LOCKFILES = ['composer.lock', 'package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml'];

    /**
     * @param  array<string, true>  $composerDeclared  package name => true (composer.json require + require-dev)
     * @param  array<string, string>  $phpPrefixes  namespace prefix with trailing backslash => package name, from composer.lock autoload maps
     * @param  array<string, true>  $npmDeclared  package name => true (package.json dependency sections)
     * @param  array<string, true>  $npmInstalled  package name => true (from the npm/yarn/pnpm lockfile)
     * @param  array<string, list<string>>  $composerRequires  package name => packages it requires, from composer.lock
     * @param  array<string, true>  $composerAutoDiscovered  package name => true when composer.lock shows extra.laravel (provider/alias discovery)
     */
    public function __construct(
        public readonly bool $hasComposerLock,
        public readonly array $composerDeclared,
        public readonly array $phpPrefixes,
        public readonly bool $hasNpmLock,
        public readonly array $npmDeclared,
        public readonly array $npmInstalled,
        public readonly array $composerRequires = [],
        public readonly array $composerAutoDiscovered = [],
    ) {}

    public static function build(string $repoPath, int $maxLockfileBytes = 8 * 1024 * 1024): self
    {
        $root = rtrim(str_replace(chr(92), '/', $repoPath), '/');

        $composer = self::readJson($root.'/composer.json', 512 * 1024) ?? [];
        $composerDeclared = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(is_array($composer[$section] ?? null) ? $composer[$section] : []) as $name) {
                $composerDeclared[strtolower((string) $name)] = true;
            }
        }

        $lock = self::readJson($root.'/composer.lock', $maxLockfileBytes);
        $phpPrefixes = [];
        $requires = [];
        $autoDiscovered = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                    continue;
                }
                $requires[strtolower($package['name'])] = array_values(array_filter(array_map(fn ($n) => strtolower((string) $n), array_keys(is_array($package['require'] ?? null) ? $package['require'] : [])), fn (string $n) => str_contains($n, '/')));
                if ((is_array($package['extra']['laravel'] ?? null) && $package['extra']['laravel'] !== []) || (is_array($package['autoload']['files'] ?? null) && $package['autoload']['files'] !== [])) {
                    // Package discovery registers the provider, or autoload.files loads global helper functions:
                    // either way the package is in use without any import or namespace reference in user code.
                    $autoDiscovered[strtolower($package['name'])] = true;
                }
                foreach (['psr-4', 'psr-0'] as $standard) {
                    foreach (array_keys(is_array($package['autoload'][$standard] ?? null) ? $package['autoload'][$standard] : []) as $prefix) {
                        $prefix = trim(str_replace('_', chr(92), (string) $prefix), chr(92));
                        if ($prefix !== '') {
                            $phpPrefixes[strtolower($prefix).chr(92)] ??= strtolower($package['name']);
                        }
                    }
                }
            }
        }

        // The repository's own autoload prefixes shadow any installed package that declares the same
        // namespace (laravel/pint ships an App\ namespace): App\Foo is the user's class, never pint's.
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0'] as $standard) {
                foreach (array_keys(is_array($composer[$section][$standard] ?? null) ? $composer[$section][$standard] : []) as $prefix) {
                    $prefix = trim(str_replace('_', chr(92), (string) $prefix), chr(92));
                    if ($prefix !== '') {
                        unset($phpPrefixes[strtolower($prefix).chr(92)]);
                    }
                }
            }
        }

        $packageJson = self::readJson($root.'/package.json', 512 * 1024) ?? [];
        $npmDeclared = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            foreach (array_keys(is_array($packageJson[$section] ?? null) ? $packageJson[$section] : []) as $name) {
                $npmDeclared[strtolower((string) $name)] = true;
            }
        }

        [$hasNpmLock, $npmInstalled] = self::npmLock($root, $maxLockfileBytes);

        return new self($lock !== null, $composerDeclared, $phpPrefixes, $hasNpmLock, $npmDeclared, $npmInstalled, $requires, $autoDiscovered);
    }

    /**
     * The package registers a Laravel service provider or alias through
     * `extra.laravel`, or loads global functions through `autoload.files`,
     * so it is in use without any import or namespace reference.
     */
    public function composerAutoDiscovered(string $package): bool
    {
        return isset($this->composerAutoDiscovered[strtolower($package)]);
    }

    public static function isLockfile(string $path): bool
    {
        return in_array(strtolower(basename(str_replace(chr(92), '/', $path))), self::LOCKFILES, true);
    }

    /**
     * The installed package that provides a fully qualified PHP name, by the
     * longest matching autoload prefix, or null when nothing installed does.
     */
    public function resolvePhp(string $fqcn): ?string
    {
        $name = strtolower(ltrim($fqcn, chr(92))).chr(92);
        $best = null;
        $bestLength = 0;

        foreach ($this->phpPrefixes as $prefix => $package) {
            if (strlen($prefix) > $bestLength && str_starts_with($name, $prefix)) {
                $best = $package;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * A declared package that directly requires the given installed package, if any.
     */
    public function composerRequiredBy(string $package): ?string
    {
        $package = strtolower($package);
        foreach ($this->composerRequires as $parent => $children) {
            if (isset($this->composerDeclared[$parent]) && in_array($package, $children, true)) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Whether a fully qualified name is plausibly provided by something the
     * project depends on: exactly, via composer.lock, or, when there is no
     * lock, because its namespace root matches a declared vendor (or a
     * well-known alias such as Illuminate => laravel).
     */
    public function probablyProvided(string $fqcn): bool
    {
        if ($this->resolvePhp($fqcn) !== null) {
            return true;
        }

        if ($this->hasComposerLock) {
            return false;
        }

        $root = strtolower(explode(chr(92), ltrim($fqcn, chr(92)))[0]);
        $vendors = [];
        foreach (array_keys($this->composerDeclared) as $package) {
            $vendors[explode('/', $package)[0]] = true;
        }

        $candidates = ComposerNamespaceScanner::ALIASES[$root] ?? [$root];

        return array_intersect($candidates, array_keys($vendors)) !== [] || (isset($vendors['laravel']) && in_array('laravel', $candidates, true));
    }

    public function composerDeclares(string $package): bool
    {
        return isset($this->composerDeclared[strtolower($package)]);
    }

    public function npmDeclares(string $package): bool
    {
        return isset($this->npmDeclared[strtolower($package)]);
    }

    public function npmInstalled(string $package): bool
    {
        return isset($this->npmInstalled[strtolower($package)]);
    }

    /**
     * @return array{0: bool, 1: array<string, true>}
     */
    private static function npmLock(string $root, int $maxBytes): array
    {
        $installed = [];

        foreach (['package-lock.json', 'npm-shrinkwrap.json'] as $file) {
            $lock = self::readJson($root.'/'.$file, $maxBytes);
            if ($lock === null) {
                continue;
            }
            foreach (array_keys(is_array($lock['packages'] ?? null) ? $lock['packages'] : []) as $path) {
                $path = (string) $path;
                $position = strrpos($path, 'node_modules/');
                if ($position !== false) {
                    $installed[strtolower(substr($path, $position + strlen('node_modules/')))] = true;
                }
            }
            self::collectV1($lock['dependencies'] ?? null, $installed);

            return [true, $installed];
        }

        $yarn = self::readText($root.'/yarn.lock', $maxBytes);
        if ($yarn !== null) {
            // Keys look like: "@scope/name@^1.0.0", name@^1.0.0, name@npm:^1.0.0:  (possibly several, comma separated)
            if (preg_match_all('/^"?(@?[^\s@"\/]+(?:\/[^\s@"\/]+)?)@[^\n]*:\s*$/m', $yarn, $m) > 0) {
                foreach ($m[1] as $name) {
                    $installed[strtolower($name)] = true;
                }
            }

            return [true, $installed];
        }

        $pnpm = self::readText($root.'/pnpm-lock.yaml', $maxBytes);
        if ($pnpm !== null) {
            // packages: section keys look like  /name@1.0.0:  or  name@1.0.0:  or  '@scope/name@1.0.0(peer)':
            if (preg_match_all('/^  [\'"]?\/?(@?[^\s@\/\'"]+(?:\/[^\s@\/\'"]+)?)@\d[^\n]*:\s*$/m', $pnpm, $m) > 0) {
                foreach ($m[1] as $name) {
                    $installed[strtolower($name)] = true;
                }
            }

            return [true, $installed];
        }

        return [false, []];
    }

    /**
     * @param  array<string, true>  $installed
     */
    private static function collectV1(mixed $dependencies, array &$installed): void
    {
        if (! is_array($dependencies)) {
            return;
        }

        foreach ($dependencies as $name => $entry) {
            $installed[strtolower((string) $name)] = true;
            if (is_array($entry)) {
                self::collectV1($entry['dependencies'] ?? null, $installed);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file, int $maxBytes): ?array
    {
        $text = self::readText($file, $maxBytes);
        if ($text === null) {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function readText(string $file, int $maxBytes): ?string
    {
        if (! is_file($file) || is_link($file) || (filesize($file) ?: 0) > $maxBytes) {
            return null;
        }

        $text = file_get_contents($file);

        return $text === false ? null : $text;
    }
}
