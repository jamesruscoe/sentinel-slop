<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;

/**
 * Findings inside a file the profile reports as unreferenced are dropped
 * before they reach the database or the reviewer: the only advice for a dead
 * file is confirm and delete, and a finding that survives would still shape
 * the phases ("create the missing request class for this dead controller").
 * The profile's own findings (unreferenced-code, missing-own-class) stay,
 * and a jscpd pair whose other half is dead goes too.
 */
final class DeadCodeFilter
{
    /**
     * @param  list<string>  $unreferenced  repository-relative paths
     */
    public function __construct(private readonly array $unreferenced, private readonly string $profileTool = 'profile') {}

    public function filter(FindingCollection $findings): FindingCollection
    {
        if ($this->unreferenced === []) {
            return $findings;
        }
        $dead = array_fill_keys($this->unreferenced, true);

        return $findings->filter(function (Finding $f) use ($dead): bool {
            // A leaked key or a backdoor is as real in a dead file as in a live one (and still caps the score).
            if ($f->tool === $this->profileTool || $f->isSecurityCritical()) {
                return true;
            }
            if (isset($dead[$f->filePath])) {
                return false;
            }

            return ! ($f->tool === 'jscpd' && preg_match('/also found in (.+):\d+\.$/', $f->message, $m) === 1 && isset($dead[$m[1]]));
        });
    }

    public function count(): int
    {
        return count($this->unreferenced);
    }
}
