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
     * @param  array<string, string>  $versions  framework/runtime name => declared version constraint, e.g. laravel => ^12.0
     */
    public function __construct(
        public readonly string $ecosystem,
        public readonly array $dependencies = [],
        public readonly array $frameworks = [],
        public readonly array $tooling = [],
        public readonly array $versions = [],
    ) {}

    /**
     * @param  array<string, string>  $constraints  package => declared constraint
     * @param  array<string, string>  $frameworkMap  package => framework
     * @param  array<string, string>  $toolingMap  package => tool
     * @param  array<string, string>  $runtimes  runtime name => declared constraint (php, node)
     */
    public static function fromConstraints(string $ecosystem, array $constraints, array $frameworkMap, array $toolingMap, array $runtimes = []): self
    {
        $frameworks = [];
        $tooling = [];
        $versions = [];

        foreach ($constraints as $package => $constraint) {
            $key = strtolower((string) $package);
            if (isset($frameworkMap[$key])) {
                $frameworks[] = $frameworkMap[$key];
                $versions[$frameworkMap[$key]] ??= $constraint;
            }
            if (isset($toolingMap[$key])) {
                $tooling[] = $toolingMap[$key];
                if ($toolingMap[$key] === 'typescript') {
                    $versions['typescript'] ??= $constraint;
                }
            }
        }

        foreach ($runtimes as $runtime => $constraint) {
            $versions[$runtime] = $constraint;
        }

        return new self(
            $ecosystem,
            array_map('strval', array_keys($constraints)),
            array_values(array_unique($frameworks)),
            array_values(array_unique($tooling)),
            array_filter($versions, fn (string $v) => $v !== ''),
        );
    }

    /**
     * For parsers without version data.
     *
     * @param  list<string>  $dependencies
     * @param  array<string, string>  $frameworkMap
     * @param  array<string, string>  $toolingMap
     */
    public static function fromDependencies(string $ecosystem, array $dependencies, array $frameworkMap, array $toolingMap): self
    {
        return self::fromConstraints($ecosystem, array_fill_keys($dependencies, ''), $frameworkMap, $toolingMap);
    }
}
