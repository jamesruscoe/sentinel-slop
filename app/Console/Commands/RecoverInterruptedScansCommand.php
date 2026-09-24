<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Exceptions\ScanInterruptedException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanFailureHandler;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fails scans whose worker went away and sweeps workspaces nothing will
 * finish. A scan is stale once nothing has touched it for longer than any
 * single stage may run (the job timeout plus a margin): every stage updates
 * the row when it starts, so a longer silence means the worker is gone. With
 * --force every running scan is failed, which is right only when this is the
 * sole worker (the entrypoint of a single worker task after a deploy).
 * Scheduled every five minutes, so an interrupted scan is reported within
 * about a job timeout even without --force.
 */
class RecoverInterruptedScansCommand extends Command
{
    private const MARGIN_SECONDS = 120;

    protected $signature = 'sentinel:recover-interrupted {--force : Fail every running scan, not only stale ones (only when this is the sole worker)}';

    protected $description = 'Fail scans whose worker went away and delete workspaces nothing will finish';

    public function handle(ScanWorkspaceFactory $workspaces, ScanFailureHandler $failures): int
    {
        $staleAfter = Carbon::now()->subSeconds((int) config('sentinel.queue.job_timeout_seconds', 2700) + self::MARGIN_SECONDS);

        $query = Scan::query()->whereNotIn('status', [ScanStatus::Complete->value, ScanStatus::Failed->value]);
        if (! $this->option('force')) {
            $query->where('updated_at', '<', $staleAfter);
        }

        $recovered = 0;
        foreach ($query->get() as $scan) {
            $failures->handle($scan, new ScanInterruptedException('The scan was interrupted by a deployment or worker restart. Please run it again.'));
            $recovered++;
        }

        $swept = $this->sweepWorkspaces($workspaces, $staleAfter);

        $this->info(sprintf('%d interrupted scan%s failed, %d workspace%s removed.', $recovered, $recovered === 1 ? '' : 's', $swept, $swept === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * Workspace directories whose scan is finished, unknown, or stale by the same rule.
     */
    private function sweepWorkspaces(ScanWorkspaceFactory $workspaces, Carbon $staleAfter): int
    {
        $base = $workspaces->basePath();
        if (! is_dir($base)) {
            return 0;
        }

        $swept = 0;
        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..' || ! is_dir($base.'/'.$name)) {
                continue;
            }

            $scan = Scan::query()->where('uuid', $name)->first();
            $finished = $scan === null || $scan->status->isTerminal();
            $stale = $scan !== null && $scan->updated_at !== null && $scan->updated_at->lessThan($staleAfter);
            $abandoned = $scan === null && filemtime($base.'/'.$name) < $staleAfter->getTimestamp();

            if (($scan !== null && $finished) || $stale || $abandoned) {
                ScanWorkspace::at($base.'/'.$name)->delete();
                $swept++;
            }
        }

        return $swept;
    }
}
