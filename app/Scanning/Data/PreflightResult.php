<?php

declare(strict_types=1);

namespace App\Scanning\Data;

final class PreflightResult
{
    /**
     * @param  list<string>  $files  Relative paths that survive preflight and may be analysed.
     * @param  list<SkippedFile>  $skipped  Files removed from the workspace during preflight.
     * @param  FindingCollection  $findings  Security findings (malware, secrets) from preflight scanners.
     */
    public function __construct(
        public readonly array $files,
        public readonly array $skipped,
        public readonly FindingCollection $findings,
        public readonly int $totalBytes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'skipped' => array_map(fn (SkippedFile $s) => $s->toArray(), $this->skipped),
            'findings' => $this->findings->toArray(),
            'total_bytes' => $this->totalBytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            files: array_values(array_map('strval', (array) ($data['files'] ?? []))),
            skipped: array_map(fn (array $s) => SkippedFile::fromArray($s), array_values((array) ($data['skipped'] ?? []))),
            findings: FindingCollection::fromArray(array_values((array) ($data['findings'] ?? []))),
            totalBytes: (int) ($data['total_bytes'] ?? 0),
        );
    }
}
