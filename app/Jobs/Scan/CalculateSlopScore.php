<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Score\LinesOfCodeCounter;
use App\Scanning\Score\SlopScoreCalculator;
use App\Services\Scanning\ScanWorkspaceFactory;

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
        $result = SlopScoreCalculator::fromConfig((array) config('sentinel.score', []))
            ->calculate($findings, $linesOfCode, (float) ($suppressions['density'] ?? 0));

        $scan->forceFill(['lines_of_code' => $linesOfCode, 'slop_score' => $result->score])->save();
        $workspace->writeArtifact('score', $result->toArray());
    }
}
