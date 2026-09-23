<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Profile\AbsenceChecks;
use App\Scanning\Score\LinesOfCodeCounter;
use App\Scanning\Score\SlopScoreCalculator;
use App\Services\Scanning\ScanWorkspaceFactory;

/**
 * Two scores: `slop_score` excludes the Structure findings from the profile
 * stage (the least certain findings we have), `slop_score_with_structure`
 * includes them. Whether they should count is still an open decision; both
 * are stored so it can be made on real numbers.
 */
class CalculateSlopScore extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Scoring;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $findings = FindingCollection::fromArray(array_values((array) (($workspace->readArtifact('findings-normalised') ?? [])['findings'] ?? [])));
        $files = array_values(array_map('strval', (array) (($workspace->readArtifact('preflight') ?? [])['files'] ?? [])));
        $suppressions = $workspace->readArtifact('suppressions') ?? [];

        $linesOfCode = LinesOfCodeCounter::count($workspace->repoPath(), $files);
        $calculator = SlopScoreCalculator::fromConfig((array) config('sentinel.score', []));
        $density = (float) ($suppressions['density'] ?? 0);

        $withoutStructure = $calculator->calculate($findings->filter(fn (Finding $f) => $f->tool !== AbsenceChecks::TOOL), $linesOfCode, $density);
        $withStructure = $calculator->calculate($findings, $linesOfCode, $density);

        $scan->forceFill(['lines_of_code' => $linesOfCode, 'slop_score' => $withoutStructure->score, 'slop_score_with_structure' => $withStructure->score])->save();
        $workspace->writeArtifact('score', $withoutStructure->toArray() + ['with_structure' => $withStructure->toArray()]);
    }
}
