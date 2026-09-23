<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;
use App\Exceptions\ScanAlreadyRunningException;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Throwable;

final class ScanDispatcher
{
    /**
     * Create a scan record and queue its pipeline on the scans queue.
     */
    public function dispatch(Repository $repository, ?User $user = null, ?string $model = null): Scan
    {
        if ($repository->scans()->whereIn('status', ScanStatus::activeValues())->exists()) {
            throw new ScanAlreadyRunningException('A scan of this repository is already in progress.');
        }

        /** @var Scan $scan */
        $scan = $repository->scans()->create([
            'user_id' => $user?->id,
            'status' => ScanStatus::Queued,
            'llm_model' => $this->resolveModel($model),
        ]);

        $scanId = $scan->id;

        Bus::chain(ScanPipeline::jobs($scan))
            ->onConnection((string) config('sentinel.queue.connection'))
            ->onQueue((string) config('sentinel.queue.name'))
            ->catch(function (Throwable $e) use ($scanId): void {
                $scan = Scan::query()->find($scanId);
                if ($scan !== null) {
                    app(ScanFailureHandler::class)->handle($scan, $e);
                }
            })
            ->dispatch();

        return $scan;
    }

    /**
     * Only models listed in config may be used; null means the default.
     */
    public function resolveModel(?string $model): string
    {
        $default = (string) config('sentinel.synthesis.model');
        /** @var list<string> $allowed */
        $allowed = config('sentinel.synthesis.models', [$default]);

        if ($model === null || $model === '') {
            return $default;
        }

        if (! in_array($model, $allowed, true)) {
            throw new InvalidArgumentException("Model {$model} is not enabled. Allowed: ".implode(', ', $allowed));
        }

        return $model;
    }
}
