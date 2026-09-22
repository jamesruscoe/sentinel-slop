<?php

namespace App\Services\Scanning;

use App\Events\ScanFailed;
use App\Events\ScanProgressed;
use App\Models\Scan;
use App\Scanning\Exceptions\ScanException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Marks a scan failed with a user-safe message and always removes its files.
 */
final class ScanFailureHandler
{
    public function __construct(private readonly ScanWorkspaceFactory $workspaces) {}

    public function handle(Scan $scan, Throwable $exception): void
    {
        $scan->refresh();

        $message = $exception instanceof ScanException
            ? $exception->userMessage()
            : 'The scan failed unexpectedly. Please try again later.';

        Log::error('Scan failed', [
            'scan' => $scan->uuid,
            'status' => $scan->status->value,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        if (! $exception instanceof ScanException) {
            report($exception);
        }

        $scan->markFailed($message);

        try {
            $this->workspaces->workspaceFor($scan)->delete();
        } catch (Throwable $cleanupFailure) {
            report($cleanupFailure);
        }

        event(new ScanProgressed($scan, $message));
        event(new ScanFailed($scan, $exception));
    }
}
