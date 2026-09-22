<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Models\Installation;
use App\Models\Repository;
use App\Models\User;
use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Fetch\LocalDirectoryContentSource;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\ScanDispatcher;
use Illuminate\Console\Command;

/**
 * Development helper: run the whole pipeline (real analysers, real LLM)
 * against a local directory and print everything it produced.
 */
class ScanFixtureCommand extends Command
{
    protected $signature = 'sentinel:scan-fixture {path : A fixture name under tests/Fixtures/repos or an absolute directory} {--model= : Prism model id} {--editor=claude_code : Editor whose prompts to print} {--no-payload : Do not print the LLM payload}';

    protected $description = 'Scan a local directory through the full pipeline synchronously and print the results (development only)';

    public function handle(ScanDispatcher $dispatcher): int
    {
        if (app()->isProduction()) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $directory = is_dir($path) ? $path : base_path('tests/Fixtures/repos/'.$path);
        if (! is_dir($directory)) {
            $this->error("No such directory: {$directory}");

            return self::FAILURE;
        }

        $name = 'local/'.basename($directory);
        $user = User::query()->firstOrCreate(['github_id' => 0], ['username' => 'local-fixtures', 'name' => 'Local fixtures']);
        $installation = Installation::query()->firstOrCreate(['github_installation_id' => 0], ['user_id' => $user->id, 'account_login' => 'local', 'account_type' => 'User']);
        $repository = Repository::query()->firstOrCreate(['github_repo_id' => crc32($name)], ['installation_id' => $installation->id, 'full_name' => $name, 'default_branch' => 'main']);

        config(['sentinel.queue.connection' => 'sync']);
        app()->instance(ContentSourceResolver::class, new class($directory) implements ContentSourceResolver
        {
            public function __construct(private readonly string $directory) {}

            public function forRepository(Repository $repository): GitHubContentSource
            {
                return new LocalDirectoryContentSource($this->directory);
            }
        });

        $model = $this->option('model');
        $scan = $dispatcher->dispatch($repository, $user, is_string($model) && $model !== '' ? $model : null);
        $scan->refresh();

        $this->line("Scan {$scan->uuid}: {$scan->status->value}".($scan->error_message ? " ({$scan->error_message})" : ''));
        $this->line("Score {$scan->slop_score}/100, {$scan->lines_of_code} lines, {$scan->findings()->count()} findings, {$scan->suppression_count} suppressions ({$scan->suppression_density}/kloc), model {$scan->llm_model}");

        if ($scan->status !== ScanStatus::Complete) {
            return self::FAILURE;
        }

        $editor = TargetEditor::from((string) $this->option('editor'));

        if (! $this->option('no-payload') && $scan->synthesis_payload !== null) {
            $this->section('LLM PAYLOAD: SYSTEM PROMPT ('.strlen($scan->synthesis_payload['system']).' chars)');
            $this->line($scan->synthesis_payload['system']);
            $this->section('LLM PAYLOAD: USER PROMPT ('.strlen($scan->synthesis_payload['user']).' chars)');
            $this->line($scan->synthesis_payload['user']);
        }

        if ($scan->synthesis_error !== null) {
            $this->section('SYNTHESIS ERROR');
            $this->line($scan->synthesis_error);
        }

        foreach ($scan->prompts()->where('target_editor', $editor)->orderBy('phase')->get() as $prompt) {
            $this->section("{$editor->label()} PROMPT, PHASE {$prompt->phase}: {$prompt->title}");
            $this->line($prompt->body);
        }

        $rules = $scan->rulesFiles()->where('target_editor', $editor)->first();
        if ($rules !== null) {
            $this->section("{$editor->label()} RULES FILE: {$rules->filename}");
            $this->line($rules->body);
        }

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line(str_repeat('=', 78));
        $this->line($title);
        $this->line(str_repeat('=', 78));
        $this->newLine();
    }
}
