<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

/**
 * What a single manifest file tells us. Pure data.
 */
final class ManifestData
{
    /**
     * @param  list<string>  $dependencies  Declared package names (runtime and dev).
     * @param  list<string>  $frameworks
     * @param  list<string>  $tooling
     */
    public function __construct(
        public readonly string $ecosystem,
        public readonly array $dependencies = [],
        public readonly array $frameworks = [],
        public readonly array $tooling = [],
    ) {}

    /**
     * @param  list<string>  $dependencies
     * @param  array<string, string>  $frameworkMap  package => framework
     * @param  array<string, string>  $toolingMap  package => tool
     */
    public static function fromDependencies(string $ecosystem, array $dependencies, array $frameworkMap, array $toolingMap): self
    {
        $frameworks = [];
        $tooling = [];

        foreach ($dependencies as $package) {
            $key = strtolower($package);
            if (isset($frameworkMap[$key])) {
                $frameworks[] = $frameworkMap[$key];
            }
            if (isset($toolingMap[$key])) {
                $tooling[] = $toolingMap[$key];
            }
        }

        return new self($ecosystem, $dependencies, array_values(array_unique($frameworks)), array_values(array_unique($tooling)));
    }
}
