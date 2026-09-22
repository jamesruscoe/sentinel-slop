<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Analysers\HeuristicRegistry;
use App\Scanning\Data\Stack;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;
use RuntimeException;

/**
 * Runs the AI-slop heuristics (php-parser based checks and the bundled
 * Semgrep slop rules) and writes each result to work/findings/.
 */
class RunSlopHeuristics extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Heuristics;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $stack = Stack::fromArray($workspace->readArtifact('stack') ?? throw new RuntimeException('The stack artifact is missing.'));

        foreach (app(HeuristicRegistry::class)->supporting($stack) as $heuristic) {
            $this->progress($scan, "Checking {$heuristic->name()}");

            $findings = $heuristic->run($workspace->repoPath());

            $workspace->writeArtifact('findings/'.$heuristic->name(), ['tool' => $heuristic->name(), 'findings' => $findings->toArray()]);
        }
    }
}
