<?php

declare(strict_types=1);

namespace App\Scanning\Data;

final class FetchResult
{
    /**
     * @param  list<string>  $files  Relative paths written to the workspace.
     * @param  list<string>  $treePaths  Every path in the tree, including ones not downloaded (data only).
     * @param  list<SkippedFile>  $skipped
     */
    public function __construct(
        public readonly string $commitSha,
        public readonly array $files,
        public readonly array $treePaths,
        public readonly int $totalBytes,
        public readonly array $skipped,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commit_sha' => $this->commitSha,
            'files' => $this->files,
            'tree_paths' => $this->treePaths,
            'total_bytes' => $this->totalBytes,
            'skipped' => array_map(fn (SkippedFile $s) => $s->toArray(), $this->skipped),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            commitSha: (string) $data['commit_sha'],
            files: array_values(array_map('strval', (array) ($data['files'] ?? []))),
            treePaths: array_values(array_map('strval', (array) ($data['tree_paths'] ?? []))),
            totalBytes: (int) ($data['total_bytes'] ?? 0),
            skipped: array_map(fn (array $s) => SkippedFile::fromArray($s), array_values((array) ($data['skipped'] ?? []))),
        );
    }
}
