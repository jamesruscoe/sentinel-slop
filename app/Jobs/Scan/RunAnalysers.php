<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Analysers\AnalyserRegistry;
use App\Scanning\Data\Stack;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;
use RuntimeException;

/**
 * Runs every analyser that supports the detected stack and writes each
 * result to work/findings/<tool>.json for NormaliseFindings.
 */
class RunAnalysers extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Analysing;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $stack = Stack::fromArray($workspace->readArtifact('stack') ?? throw new RuntimeException('The stack artifact is missing.'));

        foreach (app(AnalyserRegistry::class)->supporting($stack) as $analyser) {
            $this->progress($scan, "Running {$analyser->name()}");

            $findings = $this->attempt($scan, $workspace, $analyser->name(), fn () => $analyser->run($workspace->repoPath()));
            if ($findings === null) {
                continue;
            }

            $workspace->writeArtifact('findings/'.$analyser->name(), ['tool' => $analyser->name(), 'findings' => $findings->toArray()]);
        }
    }
}
