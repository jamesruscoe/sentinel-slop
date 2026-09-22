<?php

declare(strict_types=1);

namespace App\Scanning\Data;

final class PreflightConfig
{
    /**
     * @param  list<string>  $skippedDirectories
     * @param  list<string>  $generatedFilePatterns  Basename globs.
     */
    public function __construct(
        public readonly int $maxTotalBytes,
        public readonly int $maxFileCount,
        public readonly int $maxSingleFileBytes,
        public readonly array $skippedDirectories,
        public readonly array $generatedFilePatterns,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `sentinel` config array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            maxTotalBytes: (int) ($config['limits']['max_total_bytes'] ?? 50 * 1024 * 1024),
            maxFileCount: (int) ($config['limits']['max_file_count'] ?? 5000),
            maxSingleFileBytes: (int) ($config['limits']['max_single_file_bytes'] ?? 1024 * 1024),
            skippedDirectories: array_values(array_map('strval', (array) ($config['skipped_directories'] ?? []))),
            generatedFilePatterns: array_values(array_map('strval', (array) ($config['generated_file_patterns'] ?? []))),
        );
    }
}
