<?php

declare(strict_types=1);

namespace App\Scanning\Data;

/**
 * What the repository is built with. Everything here is derived from data
 * (GitHub's languages endpoint and parsed manifests), never from running code.
 */
final class Stack
{
    /**
     * @param  array<string, int>  $languages  Language => bytes, largest first.
     * @param  list<string>  $frameworks  e.g. laravel, react, next
     * @param  list<string>  $tooling  Tools the project claims to use, e.g. phpstan, eslint, pint
     * @param  list<string>  $manifests  Manifest files found, e.g. composer.json
     * @param  list<string>  $packageManagers  e.g. composer, npm, pnpm
     * @param  array<string, list<string>>  $dependencies  Ecosystem => declared package names
     * @param  array<string, string>  $versions  framework/runtime => declared constraint as written in the manifest, e.g. laravel => ^12.0, php => ^8.2
     */
    public function __construct(
        public readonly array $languages = [],
        public readonly array $frameworks = [],
        public readonly array $tooling = [],
        public readonly array $manifests = [],
        public readonly array $packageManagers = [],
        public readonly array $dependencies = [],
        public readonly array $versions = [],
    ) {}

    public function primaryLanguage(): ?string
    {
        return array_key_first($this->languages);
    }

    public function hasLanguage(string $language): bool
    {
        foreach (array_keys($this->languages) as $name) {
            if (strcasecmp($name, $language) === 0) {
                return true;
            }
        }

        return false;
    }

    public function hasPhp(): bool
    {
        return $this->hasLanguage('PHP') || in_array('composer.json', $this->manifests, true);
    }

    public function hasJavaScript(): bool
    {
        return $this->hasLanguage('JavaScript') || $this->hasLanguage('TypeScript')
            || $this->hasLanguage('Vue') || $this->hasLanguage('Svelte')
            || in_array('package.json', $this->manifests, true);
    }

    public function hasPython(): bool
    {
        return $this->hasLanguage('Python') || in_array('pyproject.toml', $this->manifests, true) || in_array('requirements.txt', $this->manifests, true);
    }

    public function hasTypeScript(): bool
    {
        return $this->hasLanguage('TypeScript') || in_array('typescript', $this->tooling, true);
    }

    public function hasFramework(string $framework): bool
    {
        return in_array(strtolower($framework), $this->frameworks, true);
    }

    public function usesTool(string $tool): bool
    {
        return in_array(strtolower($tool), $this->tooling, true);
    }

    /**
     * The declared constraint for a framework or runtime, e.g. "^12.0".
     */
    public function constraint(string $name): ?string
    {
        $constraint = $this->versions[strtolower($name)] ?? null;

        return $constraint === null || $constraint === '' ? null : $constraint;
    }

    /**
     * The leading version number of a declared constraint: "12" for "^12.0",
     * "8.2" for ">=8.2", null when unknown or a wildcard.
     */
    public function version(string $name): ?string
    {
        $constraint = $this->constraint($name);
        if ($constraint === null || preg_match('/(\d+)(?:\.(\d+))?/', $constraint, $m) !== 1) {
            return null;
        }

        return isset($m[2]) && $m[2] !== '0' ? $m[1].'.'.$m[2] : $m[1];
    }

    /**
     * Frameworks with their major versions where declared: "laravel 12, livewire 3".
     */
    public function describeFrameworks(): string
    {
        return implode(', ', array_map(fn (string $f) => $f.($this->version($f) !== null ? ' '.$this->version($f) : ''), $this->frameworks));
    }

    /**
     * Runtimes with declared versions: "PHP 8.2, Node 20".
     */
    public function describeRuntimes(): string
    {
        $parts = [];
        foreach (['php' => 'PHP', 'node' => 'Node', 'typescript' => 'TypeScript'] as $key => $label) {
            if ($this->version($key) !== null) {
                $parts[] = $label.' '.$this->version($key);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, float> Language => percentage of bytes.
     */
    public function languagePercentages(): array
    {
        $total = array_sum($this->languages);
        if ($total <= 0) {
            return [];
        }

        return array_map(fn (int $bytes): float => round($bytes / $total * 100, 1), $this->languages);
    }

    /**
     * @return array{languages: array<string, int>, frameworks: list<string>, tooling: list<string>, manifests: list<string>, package_managers: list<string>, dependencies: array<string, list<string>>, versions: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'languages' => $this->languages,
            'frameworks' => $this->frameworks,
            'tooling' => $this->tooling,
            'manifests' => $this->manifests,
            'package_managers' => $this->packageManagers,
            'dependencies' => $this->dependencies,
            'versions' => $this->versions,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            languages: array_map('intval', (array) ($data['languages'] ?? [])),
            frameworks: array_values(array_map('strval', (array) ($data['frameworks'] ?? []))),
            tooling: array_values(array_map('strval', (array) ($data['tooling'] ?? []))),
            manifests: array_values(array_map('strval', (array) ($data['manifests'] ?? []))),
            packageManagers: array_values(array_map('strval', (array) ($data['package_managers'] ?? []))),
            dependencies: array_map(fn ($names) => array_values(array_map('strval', (array) $names)), (array) ($data['dependencies'] ?? [])),
            versions: array_map('strval', (array) ($data['versions'] ?? [])),
        );
    }
}
