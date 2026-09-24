<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Events\ScanProgressed;
use App\Models\Scan;
use App\Scanning\Analysers\AnalyserFailure;
use App\Scanning\Exceptions\AnalyserFailedException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One stage of the scan pipeline. Each stage moves the scan to its status,
 * broadcasts progress, then does its work against the scan's workspace.
 * Any exception aborts the chain; ScanFailureHandler takes over from there.
 */
abstract class ScanStageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Scan $scan)
    {
        $this->onConnection((string) config('sentinel.queue.connection'));
        $this->onQueue((string) config('sentinel.queue.name'));
        $this->timeout = (int) config('sentinel.queue.job_timeout_seconds', 2700);
    }

    /** The status this stage represents, or null for housekeeping jobs. */
    abstract protected function status(): ?ScanStatus;

    abstract protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void;

    public function handle(ScanWorkspaceFactory $workspaces): void
    {
        $scan = $this->scan->fresh();

        if ($scan === null || $scan->status === ScanStatus::Failed) {
            return;
        }

        $status = $this->status();
        if ($status !== null) {
            $scan->transitionTo($status);
            event(new ScanProgressed($scan));
        }

        $this->process($scan, $workspaces->workspaceFor($scan), $workspaces);
    }

    protected function progress(Scan $scan, string $message): void
    {
        event(new ScanProgressed($scan, $message));
    }

    /**
     * Run one analyser or heuristic without letting it take the scan down.
     * A timeout, a crash or unreadable output is recorded on the scan (and in
     * the `analyser-failures` artifact) and the pipeline carries on: a repository
     * where every other tool succeeded still gets its findings, score and
     * review, and the coverage line says which tool is missing. Only fetch,
     * preflight and normalisation failures make a scan meaningless.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T|null
     */
    protected function attempt(Scan $scan, ScanWorkspace $workspace, string $tool, callable $run): mixed
    {
        $started = microtime(true);

        try {
            return $run();
        } catch (Throwable $exception) {
            $failure = AnalyserFailure::from($tool, $exception, microtime(true) - $started);

            if (! $exception instanceof AnalyserFailedException) {
                report($exception);
            }
            Log::warning('Analyser did not complete; the scan continues without it', ['scan' => $scan->uuid, 'tool' => $tool, 'kind' => $failure->kind, 'message' => $failure->message]);

            $failures = (array) ($workspace->readArtifact('analyser-failures') ?? []);
            $failures[] = $failure->toArray();
            $workspace->writeArtifact('analyser-failures', $failures);
            $scan->forceFill(['analyser_failures' => $failures])->save();

            $this->progress($scan, ucfirst($failure->sentence()));

            return null;
        }
    }
}
