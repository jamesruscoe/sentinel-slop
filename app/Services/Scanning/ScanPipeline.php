<?php

namespace App\Services\Scanning;

use App\Jobs\Scan\CleanupScan;
use App\Jobs\Scan\CompleteScan;
use App\Jobs\Scan\DetectStack;
use App\Jobs\Scan\FetchRepository;
use App\Jobs\Scan\NormaliseFindings;
use App\Jobs\Scan\PreflightCheck;
use App\Jobs\Scan\RunAnalysers;
use App\Jobs\Scan\RunSlopHeuristics;
use App\Models\Scan;

/**
 * The ordered job chain for one scan. Phase 4 inserts CalculateSlopScore and
 * SynthesisePrompts after NormaliseFindings. Cleanup always runs before the
 * scan is marked complete; failures are cleaned up by ScanFailureHandler.
 *
 * @return list<object>
 */
final class ScanPipeline
{
    /**
     * @return list<object>
     */
    public static function jobs(Scan $scan): array
    {
        return [
            new FetchRepository($scan),
            new PreflightCheck($scan),
            new DetectStack($scan),
            new RunAnalysers($scan),
            new RunSlopHeuristics($scan),
            new NormaliseFindings($scan),
            new CleanupScan($scan),
            new CompleteScan($scan),
        ];
    }
}
