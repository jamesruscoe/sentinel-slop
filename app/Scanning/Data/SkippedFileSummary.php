<?php

declare(strict_types=1);

namespace App\Scanning\Data;

/**
 * The shape stored in `scans.skipped_files`: per-reason counts plus a capped
 * list of entries so the user can see what was left out and why.
 */
final class SkippedFileSummary
{
    /**
     * @param  list<SkippedFile>  $skipped
     * @return array{total: int, counts: array<string, int>, entries: list<array{path: string, reason: string, detail: string|null}>, truncated: bool}
     */
    public static function build(array $skipped, int $maxEntries = 500): array
    {
        $counts = [];
        foreach ($skipped as $file) {
            $counts[$file->reason->value] = ($counts[$file->reason->value] ?? 0) + 1;
        }
        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return [
            'total' => count($skipped),
            'counts' => $counts,
            'entries' => array_map(fn (SkippedFile $s) => $s->toArray(), array_slice($skipped, 0, $maxEntries)),
            'truncated' => count($skipped) > $maxEntries,
        ];
    }
}
