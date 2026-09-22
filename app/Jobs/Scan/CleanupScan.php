<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;

/**
 * Deletes the scan's temp directory. Runs at the end of every successful
 * chain; ScanFailureHandler does the same on failure and the sweeper catches
 * anything left behind.
 */
class CleanupScan extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return null;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $workspace->delete();
    }
}
