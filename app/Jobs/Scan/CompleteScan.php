<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Events\ScanCompleted;
use App\Models\Scan;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;

class CompleteScan extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Complete;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        event(new ScanCompleted($scan));
    }
}
