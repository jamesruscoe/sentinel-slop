<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\Scanning\ScanDispatcher;
use Illuminate\Console\Command;
use Throwable;

class ScanRepositoryCommand extends Command
{
    protected $signature = 'sentinel:scan {repository : Full name, e.g. owner/name} {--model= : Prism model id for prompt synthesis}';

    protected $description = 'Queue a scan of a repository the GitHub App is installed on';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $fullName = (string) $this->argument('repository');
        $repository = Repository::query()->active()->where('full_name', $fullName)->first();

        if ($repository === null) {
            $this->error("No active repository named {$fullName}. Install the GitHub App on it first.");

            return self::FAILURE;
        }

        $model = $this->option('model');

        try {
            $scan = $dispatcher->dispatch($repository, null, is_string($model) && $model !== '' ? $model : null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Scan {$scan->uuid} queued for {$fullName} (model: {$scan->llm_model}).");

        return self::SUCCESS;
    }
}
