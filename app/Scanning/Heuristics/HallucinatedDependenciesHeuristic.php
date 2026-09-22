<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Imports of packages that no manifest declares: explicit package names in
 * JS/TS, vendor namespaces matched against composer vendors in PHP.
 */
final class HallucinatedDependenciesHeuristic implements Heuristic
{
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

        $composer = self::readJson($path.'/composer.json');
        if ($composer !== null) {
            foreach ($this->composer->scan($path, $composer) as $hit) {
                $findings->add(new Finding($this->name(), 'undeclared-php-namespace', FindingCategory::HallucinatedDependency, Severity::Medium, $hit['file'], $hit['line'],
                    "Namespace {$hit['namespace']} is imported but no composer dependency provides it; it may be hallucinated or an undeclared transitive dependency."));
            }
        }

        $package = self::readJson($path.'/package.json');
        if ($package !== null) {
            $workspaces = isset($package['workspaces']);
            foreach ($this->npm->scan($path, $package, self::readJson($path.'/tsconfig.json')) as $hit) {
                $findings->add(new Finding($this->name(), 'undeclared-npm-package', FindingCategory::HallucinatedDependency, $workspaces ? Severity::Low : Severity::Medium, $hit['file'], $hit['line'],
                    "Package \"{$hit['package']}\" is imported but not declared in package.json; it may be hallucinated.".($workspaces ? ' Workspaces are configured, so it may be a workspace package.' : '')));
            }
        }

        return $findings;
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
