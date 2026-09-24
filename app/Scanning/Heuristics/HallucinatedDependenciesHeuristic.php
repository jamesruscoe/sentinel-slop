<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Detect\DependencyIndex;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Imports resolved against what is actually installed (lockfiles) and what
 * the manifests declare. Three outcomes, never collapsed:
 *
 *   installed and declared            → nothing
 *   installed but not declared        → Low   "undeclared transitive dependency; declare it"
 *   not installed anywhere            → Medium "may be hallucinated"
 *
 * Without a lockfile nothing can be verified, so the finding says so and
 * stays Low instead of guessing from the manifest alone.
 */
final class HallucinatedDependenciesHeuristic implements Heuristic
{
    /**
     * Packages whose types a framework hands to application code through its
     * own public API (Carbon dates, Symfony responses, Monolog handlers in
     * config/logging.php). Using them is using the framework, not a hidden
     * transitive dependency, so they are not reported while that framework
     * is declared. Anything else installed transitively is still flagged.
     *
     * @var array<string, list<string>> declared framework package => provider package prefixes
     */
    private const FRAMEWORK_SURFACE = [
        'laravel/framework' => ['nesbot/carbon', 'symfony/', 'monolog/monolog', 'psr/', 'league/flysystem', 'brick/math', 'ramsey/uuid', 'illuminate/', 'vlucas/phpdotenv', 'egulias/email-validator', 'guzzlehttp/'],
        'symfony/framework-bundle' => ['symfony/', 'psr/', 'doctrine/', 'monolog/monolog', 'twig/'],
    ];

    public function __construct(
        private readonly ComposerNamespaceScanner $composer = new ComposerNamespaceScanner,
        private readonly NpmImportScanner $npm = new NpmImportScanner,
    ) {}

    public function name(): string
    {
        return 'hallucinated-dependencies';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp() || $stack->hasJavaScript();
    }

    public function run(string $path): FindingCollection
    {
        $findings = new FindingCollection;
        $index = DependencyIndex::build($path);

        $composer = self::readJson($path.'/composer.json');
        if ($composer !== null) {
            $this->php($path, $composer, $index, $findings);
        }

        $package = self::readJson($path.'/package.json');
        if ($package !== null) {
            $this->npm($path, $package, $index, $findings);
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function php(string $path, array $composer, DependencyIndex $index, FindingCollection $findings): void
    {
        /** @var array<string, true> $reported  package or namespace prefix already reported */
        $reported = [];

        foreach ($this->composer->scan($path, $composer) as $hit) {
            // A stub or template imports what the generated project declares, not what this repository declares.
            if (SourceFiles::isTemplateFile($hit['file'])) {
                continue;
            }
            $package = $index->resolvePhp($hit['name']);

            if ($package !== null) {
                if ($index->composerDeclares($package) || isset($reported[$package]) || self::frameworkSurface($package, $index)) {
                    continue;
                }
                $reported[$package] = true;
                $via = $index->composerRequiredBy($package);
                $findings->add(new Finding($this->name(), 'undeclared-transitive-php-package', FindingCategory::HallucinatedDependency, Severity::Low, $hit['file'], $hit['line'],
                    "{$hit['name']} is provided by {$package}, which is not in composer.json".($via !== null ? " (it is installed because {$via} requires it)" : ' (installed only as a transitive dependency)').'; declare it explicitly.'));

                continue;
            }

            $prefix = implode(chr(92), array_slice(explode(chr(92), $hit['name']), 0, 2));
            if (isset($reported[$prefix])) {
                continue;
            }
            $reported[$prefix] = true;

            if (! $index->hasComposerLock) {
                if (self::vendorDeclared($hit['name'], $composer)) {
                    continue;
                }
                $findings->add(new Finding($this->name(), 'unverifiable-php-namespace', FindingCategory::HallucinatedDependency, Severity::Low, $hit['file'], $hit['line'],
                    "Namespace {$prefix} does not obviously belong to any package in composer.json, and there is no composer.lock to check what is installed. Commit composer.lock or confirm the package exists."));

                continue;
            }

            $findings->add(new Finding($this->name(), 'hallucinated-php-namespace', FindingCategory::HallucinatedDependency, Severity::Medium, $hit['file'], $hit['line'],
                "Namespace {$prefix} is not provided by any package in composer.lock; the import may be hallucinated."));
        }
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function npm(string $path, array $package, DependencyIndex $index, FindingCollection $findings): void
    {
        $workspaces = isset($package['workspaces']);

        foreach ($this->npm->scan($path, $package, self::readJson($path.'/tsconfig.json')) as $hit) {
            if (SourceFiles::isTemplateFile($hit['file'])) {
                continue;
            }
            $name = $hit['package'];

            if ($index->npmDeclares($name)) {
                continue;
            }

            if ($index->npmInstalled($name)) {
                $findings->add(new Finding($this->name(), 'undeclared-transitive-npm-package', FindingCategory::HallucinatedDependency, Severity::Low, $hit['file'], $hit['line'],
                    "Package \"{$name}\" is imported and installed, but only as a transitive dependency; declare it explicitly in package.json."));

                continue;
            }

            if (! $index->hasNpmLock) {
                $findings->add(new Finding($this->name(), 'unverifiable-npm-package', FindingCategory::HallucinatedDependency, Severity::Low, $hit['file'], $hit['line'],
                    "Package \"{$name}\" is imported but not declared in package.json, and there is no lockfile to check whether it is installed. Commit the lockfile or declare the package.".($workspaces ? ' Workspaces are configured, so it may be a workspace package.' : '')));

                continue;
            }

            $findings->add(new Finding($this->name(), 'hallucinated-npm-package', FindingCategory::HallucinatedDependency, $workspaces ? Severity::Low : Severity::Medium, $hit['file'], $hit['line'],
                "Package \"{$name}\" is imported but neither declared in package.json nor present in the lockfile; the import may be hallucinated.".($workspaces ? ' Workspaces are configured, so it may be a workspace package.' : '')));
        }
    }

    private static function frameworkSurface(string $package, DependencyIndex $index): bool
    {
        foreach (self::FRAMEWORK_SURFACE as $framework => $prefixes) {
            if (! $index->composerDeclares($framework)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($package, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Manifest-only fallback when there is no composer.lock: the namespace
     * root matches a declared vendor (or a well-known alias of one).
     *
     * @param  array<string, mixed>  $composer
     */
    private static function vendorDeclared(string $fqcn, array $composer): bool
    {
        $vendors = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(is_array($composer[$section] ?? null) ? $composer[$section] : []) as $package) {
                $vendors[strtolower(explode('/', (string) $package)[0])] = true;
            }
        }

        $root = strtolower(explode(chr(92), ltrim($fqcn, chr(92)))[0]);
        $candidates = ComposerNamespaceScanner::ALIASES[$root] ?? [$root];

        return array_intersect($candidates, array_keys($vendors)) !== [] || (isset($vendors['laravel']) && in_array('laravel', $candidates, true));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $file): ?array
    {
        if (! is_file($file) || (filesize($file) ?: 0) > 512 * 1024) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }
}
