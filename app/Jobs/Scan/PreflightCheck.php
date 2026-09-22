<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\FetchResult;
use App\Scanning\Data\PreflightConfig;
use App\Scanning\Data\SkippedFileSummary;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Preflight\PreflightChecker;
use App\Services\Scanning\ScanWorkspaceFactory;
use RuntimeException;

class PreflightCheck extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Preflight;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $fetch = FetchResult::fromArray($workspace->readArtifact('fetch') ?? throw new RuntimeException('The fetch artifact is missing.'));

        $result = app(PreflightChecker::class)->check($workspace, PreflightConfig::fromConfig(config('sentinel')));

        $scan->forceFill([
            'skipped_files' => SkippedFileSummary::build([...$fetch->skipped, ...$result->skipped]),
        ])->save();

        $workspace->writeArtifact('preflight', $result->toArray());
    }
}
