<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;

/**
 * Collapses overlapping findings: exact repeats from one tool, and findings
 * from different tools that flag the same category on the same line. The
 * most severe one wins.
 */
final class FindingDeduplicator
{
    public function dedupe(FindingCollection $findings): FindingCollection
    {
        /** @var array<string, Finding> $byExact */
        $byExact = [];
        /** @var array<string, string> $byOverlap  overlap key => exact key kept */
        $byOverlap = [];

        foreach ($findings as $finding) {
            $exactKey = implode('|', [$finding->tool, (string) $finding->ruleId, $finding->filePath, (string) $finding->line]);
            $overlapKey = implode('|', [$finding->category->value, $finding->filePath, (string) $finding->line]);

            if (isset($byExact[$exactKey])) {
                $byExact[$exactKey] = self::moreSevere($byExact[$exactKey], $finding);

                continue;
            }

            if ($finding->line !== null && isset($byOverlap[$overlapKey])) {
                $keptKey = $byOverlap[$overlapKey];
                $byExact[$keptKey] = self::moreSevere($byExact[$keptKey], $finding);

                continue;
            }

            $byExact[$exactKey] = $finding;
            if ($finding->line !== null) {
                $byOverlap[$overlapKey] = $exactKey;
            }
        }

        return new FindingCollection(array_values($byExact));
    }

    private static function moreSevere(Finding $a, Finding $b): Finding
    {
        return $b->severity->rank() > $a->severity->rank() ? $b : $a;
    }
}
