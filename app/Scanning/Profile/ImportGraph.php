<?php

declare(strict_types=1);

namespace App\Scanning\Profile;

/**
 * File-to-file edges inside the repository. PHP references are fully
 * qualified names resolved through the repository's own PSR-4 map in
 * composer.json; JS/TS/Vue imports are relative paths or tsconfig aliases;
 * Python imports are dotted modules against the repo root, src/ and the
 * importing file's package; Ruby uses require_relative. Anything that does
 * not resolve to a repository file is not an edge (it is a package, or
 * nothing). Nothing is executed.
 */
final class ImportGraph
{
    /**
     * @param  array<string, list<string>>  $edges  importer path => imported paths
     * @param  array<string, list<string>>  $reverse  imported path => importer paths
     * @param  array<string, string>  $psr4  namespace prefix (trailing backslash) => directory (trailing slash), own code only
     * @param  list<string>  $globbed  directory prefixes referenced by a glob (import.meta.glob), whose files count as referenced
     */
    private function __construct(
        public readonly array $edges,
        public readonly array $reverse,
        public readonly array $psr4,
        public readonly array $globbed = [],
    ) {}

    /**
     * Whether some file references this path through a glob pattern over its directory.
     */
    public function isGlobbed(string $path): bool
    {
        foreach ($this->globbed as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{path: string, family: string|null, imports: list<string>, mentions?: list<string>, is_config_path?: bool}>  $files
     */
    public static function build(string $repoPath, array $files): self
    {
        $exists = [];
        foreach ($files as $file) {
            $exists[$file['path']] = true;
        }

        $psr4 = self::ownPsr4($repoPath);
        $aliases = self::jsAliases($repoPath, $exists);
        $pageRoots = self::pageRoots($exists);
        $edges = [];
        $reverse = [];
        $globbed = [];

        foreach ($files as $file) {
            $targets = [];
            if ($file['family'] !== null) {
                foreach ($file['imports'] as $import) {
                    $target = match ($file['family']) {
                        'php' => self::resolvePhp($import, $psr4, $exists),
                        'js' => self::resolveJs($import, $file['path'], $aliases, $exists),
                        'python' => self::resolvePython($import, $file['path'], $exists),
                        'ruby' => self::resolveRuby($import, $file['path'], $exists),
                        default => null,
                    };
                    if ($target !== null && $target !== $file['path']) {
                        $targets[$target] = true;
                    }
                }
            }
            // String mentions: routes/kennel.php in bootstrap/app.php, 'Staff/Dogs/Index' in Inertia::render(),
            // 'emails.booking' in view(), app/helpers.php in composer.json, resources/js/app.ts in @vite().
            foreach ($file['mentions'] ?? [] as $mention) {
                if (str_starts_with($mention, 'glob:')) {
                    // Only globs in application code load files (import.meta.glob, require.context). A tsconfig
                    // include, tailwind content list or eslint pattern covering resources/js/** references nothing.
                    if (in_array($file['family'], ['js', 'php', 'python'], true) && ! ($file['is_config_path'] ?? false)) {
                        $prefix = self::globPrefix(substr($mention, 5), $file['path'], $aliases);
                        if ($prefix !== null) {
                            $globbed[$prefix] = true;
                        }
                    }

                    continue;
                }
                $target = self::resolveMention($mention, $file['path'], $exists, $pageRoots);
                if ($target !== null && $target !== $file['path']) {
                    $targets[$target] = true;
                }
            }
            if ($targets === [] && $file['family'] === null) {
                continue;
            }

            $edges[$file['path']] = array_keys($targets);
            foreach (array_keys($targets) as $target) {
                $reverse[$target][] = $file['path'];
            }
        }

        return new self($edges, $reverse, $psr4, array_keys($globbed));
    }

    /**
     * The directory a glob pattern covers, resolved like a relative or aliased import.
     *
     * @param  array<string, string>  $aliases
     */
    private static function globPrefix(string $glob, string $importer, array $aliases): ?string
    {
        $base = explode('*', $glob)[0];
        $base = preg_replace('~[^/]*$~', '', $base) ?? $base;
        if ($base === '') {
            return null;
        }
        if (str_starts_with($base, './') || str_starts_with($base, '../')) {
            return rtrim(self::normalise(dirname($importer).'/'.$base), '/').'/';
        }
        foreach ($aliases as $prefix => $dir) {
            if (str_starts_with($base, $prefix)) {
                return rtrim(self::normalise($dir.substr($base, strlen($prefix))), '/').'/';
            }
        }
        if (str_starts_with($base, '/')) {
            return rtrim(self::normalise($base), '/').'/';
        }

        return rtrim(self::normalise($base), '/').'/';
    }

    /**
     * @return list<string>
     */
    public function importsOf(string $path): array
    {
        return $this->edges[$path] ?? [];
    }

    /**
     * @return list<string>
     */
    public function importedBy(string $path): array
    {
        return $this->reverse[$path] ?? [];
    }

    /**
     * Files reachable from $path by following imports up to $hops times (excluding $path).
     *
     * @return list<string>
     */
    public function reachable(string $path, int $hops): array
    {
        $seen = [$path => true];
        $frontier = [$path];

        for ($i = 0; $i < $hops && $frontier !== []; $i++) {
            $next = [];
            foreach ($frontier as $current) {
                foreach ($this->importsOf($current) as $target) {
                    if (! isset($seen[$target])) {
                        $seen[$target] = true;
                        $next[] = $target;
                    }
                }
            }
            $frontier = $next;
        }

        unset($seen[$path]);

        return array_keys($seen);
    }

    public function resolvePhpName(string $fqcn): ?string
    {
        return self::resolvePhp($fqcn, $this->psr4, array_fill_keys(array_keys($this->edges), true));
    }

    /**
     * Whether a fully qualified PHP name falls under one of the repository's own autoload prefixes.
     */
    public function isOwnPhpName(string $fqcn): bool
    {
        $fqcn = ltrim($fqcn, chr(92));
        foreach (array_keys($this->psr4) as $prefix) {
            if (str_starts_with($fqcn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function ownPsr4(string $repoPath): array
    {
        $composer = self::readJson($repoPath.'/composer.json');
        $map = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) (($composer[$section] ?? [])['psr-4'] ?? []) as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    $map[rtrim((string) $prefix, chr(92)).chr(92)] = rtrim(str_replace(chr(92), '/', (string) $dir), '/').'/';
                }
            }
        }

        if ($map === [] && is_dir($repoPath.'/app')) {
            $map['App'.chr(92)] = 'app/';
        }

        uksort($map, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $map;
    }

    /**
     * @param  array<string, string>  $psr4
     * @param  array<string, true>  $exists
     */
    private static function resolvePhp(string $fqcn, array $psr4, array $exists): ?string
    {
        $fqcn = ltrim($fqcn, chr(92));
        foreach ($psr4 as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                $candidate = $dir.str_replace(chr(92), '/', substr($fqcn, strlen($prefix))).'.php';
                $candidate = ltrim($candidate, './');
                if (isset($exists[$candidate])) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $exists
     * @return array<string, string> alias prefix (e.g. "@/") => directory (trailing slash)
     */
    private static function jsAliases(string $repoPath, array $exists): array
    {
        $aliases = [];
        foreach (['tsconfig.json', 'jsconfig.json', 'tsconfig.app.json'] as $name) {
            $config = self::readJson($repoPath.'/'.$name);
            $baseUrl = rtrim(str_replace(chr(92), '/', (string) (($config['compilerOptions'] ?? [])['baseUrl'] ?? '.')), '/');
            foreach ((array) (($config['compilerOptions'] ?? [])['paths'] ?? []) as $pattern => $targets) {
                $first = (array) $targets;
                if ($first === []) {
                    continue;
                }
                $prefix = rtrim((string) $pattern, '*');
                $target = rtrim((string) $first[0], '*');
                $target = ltrim(($baseUrl === '.' || $baseUrl === '' ? '' : $baseUrl.'/').ltrim($target, './'), '/');
                $aliases[$prefix] = rtrim($target, '/').'/';
            }
        }

        if (! isset($aliases['@/'])) {
            foreach (['resources/js/', 'src/', 'resources/ts/', 'app/'] as $dir) {
                foreach (array_keys($exists) as $path) {
                    if (str_starts_with($path, $dir)) {
                        $aliases['@/'] = $dir;

                        break 2;
                    }
                }
            }
        }

        return $aliases;
    }

    /**
     * @param  array<string, string>  $aliases
     * @param  array<string, true>  $exists
     */
    private static function resolveJs(string $specifier, string $importer, array $aliases, array $exists): ?string
    {
        $base = null;
        if (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            $base = self::normalise(dirname($importer).'/'.$specifier);
        } else {
            foreach ($aliases as $prefix => $dir) {
                if (str_starts_with($specifier, $prefix)) {
                    $base = self::normalise($dir.substr($specifier, strlen($prefix)));

                    break;
                }
            }
        }
        if ($base === null) {
            return null;
        }

        $base = preg_replace('/\?.*$/', '', $base) ?? $base;
        $candidates = [$base];
        foreach (['ts', 'tsx', 'js', 'jsx', 'vue', 'mjs', 'cjs', 'svelte', 'mts', 'd.ts'] as $ext) {
            $candidates[] = $base.'.'.$ext;
            $candidates[] = $base.'/index.'.$ext;
        }
        foreach ($candidates as $candidate) {
            if (isset($exists[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $exists
     */
    private static function resolvePython(string $module, string $importer, array $exists): ?string
    {
        $relativeDots = strlen($module) - strlen(ltrim($module, '.'));
        $name = ltrim($module, '.');
        $parts = $name === '' ? [] : explode('.', $name);

        $roots = ['', 'src/', 'app/', 'lib/'];
        if ($relativeDots > 0) {
            $dir = dirname($importer);
            for ($i = 1; $i < $relativeDots; $i++) {
                $dir = dirname($dir);
            }
            $roots = [$dir === '.' ? '' : $dir.'/'];
        }

        foreach ($roots as $root) {
            for ($take = count($parts); $take >= 1; $take--) {
                $path = $root.implode('/', array_slice($parts, 0, $take));
                foreach ([$path.'.py', $path.'/__init__.py'] as $candidate) {
                    if (isset($exists[$candidate])) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $exists
     */
    private static function resolveRuby(string $required, string $importer, array $exists): ?string
    {
        $candidate = self::normalise(dirname($importer).'/'.$required);
        foreach ([$candidate, $candidate.'.rb', 'lib/'.$required.'.rb', 'app/'.$required.'.rb'] as $path) {
            if (isset($exists[$path])) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Directories whose files are addressed by name from elsewhere (Inertia pages, Blade views).
     *
     * @param  array<string, true>  $exists
     * @return list<string> directory prefixes with trailing slash
     */
    private static function pageRoots(array $exists): array
    {
        $roots = [];
        foreach (['resources/js/pages/', 'resources/js/Pages/', 'resources/ts/pages/', 'resources/ts/Pages/', 'src/pages/', 'src/Pages/', 'resources/views/'] as $root) {
            foreach (array_keys($exists) as $path) {
                if (str_starts_with($path, $root)) {
                    $roots[] = $root;

                    break;
                }
            }
        }

        return $roots;
    }

    /**
     * @param  array<string, true>  $exists
     * @param  list<string>  $pageRoots
     */
    private static function resolveMention(string $mention, string $mentioner, array $exists, array $pageRoots): ?string
    {
        $clean = self::normalise(preg_replace('~^(?:\./|\.\./)+~', '', ltrim($mention, '/')) ?? $mention);
        if ($clean === '') {
            return null;
        }

        // `require __DIR__.'/auth.php'` in routes/web.php mentions "/auth.php": try next to the mentioning file first.
        $dir = dirname($mentioner);
        $candidates = [$clean, ($dir === '.' ? '' : $dir.'/').$clean, self::normalise($dir.'/'.ltrim($mention, '/'))];
        if (! str_contains($clean, '.') || ! preg_match('/\.[a-z]{1,5}$/i', $clean)) {
            foreach (['php', 'js', 'ts', 'vue', 'tsx', 'jsx', 'py', 'rb'] as $ext) {
                $candidates[] = $clean.'.'.$ext;
            }
        }
        foreach ($pageRoots as $root) {
            $name = str_ends_with($root, 'views/') ? str_replace('.', '/', $clean) : $clean;
            foreach (['vue', 'tsx', 'jsx', 'ts', 'js', 'svelte', 'blade.php'] as $ext) {
                $candidates[] = $root.$name.'.'.$ext;
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($exists[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace(chr(92), '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        if (! is_file($file) || (filesize($file) ?: 0) > 512 * 1024) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
