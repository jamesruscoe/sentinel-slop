<?php

declare(strict_types=1);

namespace App\Scanning\Data;

use App\Scanning\Detect\DependencyIndex;
use App\Scanning\Support\PathMatcher;

final class FetchLimits
{
    /**
     * @param  list<string>  $skippedDirectories  Directory names or relative paths never downloaded.
     * @param  list<string>  $skippedExtensions  Lower-case extensions (no dot) no analyser reads: never downloaded, never counted.
     * @param  list<string>  $generatedFilePatterns  Basename globs preflight would delete anyway: never downloaded, never counted.
     */
    public function __construct(
        public readonly int $maxTotalBytes,
        public readonly int $maxFileCount,
        public readonly int $maxSingleFileBytes,
        public readonly array $skippedDirectories,
        public readonly int $maxLockfileBytes = 8 * 1024 * 1024,
        public readonly array $skippedExtensions = [],
        public readonly array $generatedFilePatterns = [],
    ) {}

    /**
     * Lockfiles may be much larger than ordinary source files and are needed to resolve imports.
     */
    public function limitFor(string $path): int
    {
        return DependencyIndex::isLockfile($path) ? max($this->maxSingleFileBytes, $this->maxLockfileBytes) : $this->maxSingleFileBytes;
    }

    /**
     * @param  array<string, mixed>  $config  The `sentinel` config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            maxTotalBytes: (int) ($config['limits']['max_total_bytes'] ?? 50 * 1024 * 1024),
            maxFileCount: (int) ($config['limits']['max_file_count'] ?? 5000),
            maxSingleFileBytes: (int) ($config['limits']['max_single_file_bytes'] ?? 1024 * 1024),
            maxLockfileBytes: (int) ($config['limits']['max_lockfile_bytes'] ?? 8 * 1024 * 1024),
            skippedDirectories: array_values(array_map('strval', (array) ($config['skipped_directories'] ?? []))),
            skippedExtensions: array_values(array_map(fn ($e) => strtolower(ltrim((string) $e, '.')), (array) ($config['skipped_extensions'] ?? []))),
            generatedFilePatterns: array_values(array_map('strval', (array) ($config['generated_file_patterns'] ?? []))),
        );
    }

    /**
     * Files no analyser ever reads, decided from the path alone so the limits
     * count what would be analysed rather than what the tree contains.
     */
    public function isNotAnalysed(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $this->skippedExtensions, true);
    }

    public function isGenerated(string $path): bool
    {
        $basename = basename($path);
        foreach ($this->generatedFilePatterns as $pattern) {
            if (PathMatcher::matchesGlob($basename, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
