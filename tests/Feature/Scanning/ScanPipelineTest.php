<?php

use App\Enums\ScanStatus;
use App\Events\ScanCompleted;
use App\Events\ScanFailed;
use App\Events\ScanProgressed;
use App\Exceptions\ScanAlreadyRunningException;
use App\Models\Installation;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;
use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\ScanDispatcher;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeContentSource;

beforeEach(function () {
    $base = sys_get_temp_dir().'/sentinel-tests/pipeline-'.bin2hex(random_bytes(4));
    config()->set('sentinel.scan_storage_path', $base);
    app()->forgetInstance(ScanWorkspaceFactory::class);
    $this->storage = $base;

    $this->repository = Repository::factory()->for(Installation::factory()->for(User::factory()))->create(['full_name' => 'acme/laravel-basic', 'default_branch' => null]);
    $this->source = FakeContentSource::fromDirectory(fixturePath('laravel-basic'));
    $this->source->languages = ['PHP' => 9000, 'Blade' => 500];

    app()->instance(ContentSourceResolver::class, new class($this->source) implements ContentSourceResolver
    {
        public function __construct(private GitHubContentSource $source) {}

        public function forRepository(Repository $repository): GitHubContentSource
        {
            return $this->source;
        }
    });
});

afterEach(function () {
    ScanWorkspace::at($this->storage)->delete();
});

test('a scan runs through the pipeline, stores results and deletes its files', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);

    $scan = app(ScanDispatcher::class)->dispatch($this->repository, $this->repository->installation->user);
    $scan->refresh();

    expect($scan->status)->toBe(ScanStatus::Complete)
        ->and($scan->commit_sha)->toBe($this->source->headSha)
        ->and($scan->llm_model)->toBe(config('sentinel.synthesis.model'))
        ->and($scan->detected_stack['frameworks'])->toBe(['laravel', 'livewire'])
        ->and($scan->detected_stack['languages'])->toBe(['PHP' => 9000, 'Blade' => 500])
        ->and($scan->skipped_files['counts'])->toBe(['binary' => 1, 'generated' => 1, 'minified' => 1])
        ->and($scan->findings()->count())->toBe(0)
        ->and($scan->started_at)->not->toBeNull()
        ->and($scan->finished_at)->not->toBeNull()
        ->and($this->repository->fresh()->default_branch)->toBe('main')
        ->and(is_dir($this->storage.'/'.$scan->uuid))->toBeFalse();

    $statuses = collect(Event::dispatched(ScanProgressed::class))->map(fn (array $args) => $args[0]->scan->status->value)->unique()->values()->all();
    expect($statuses)->toBe(['fetching', 'preflight', 'detecting', 'normalising', 'complete']);
    Event::assertDispatched(ScanCompleted::class);
    Event::assertNotDispatched(ScanFailed::class);
});

test('a limit failure marks the scan failed with the user-facing reason and cleans up', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
    $this->source->truncated = true;

    try {
        app(ScanDispatcher::class)->dispatch($this->repository);
    } catch (FetchLimitExceededException) {
        // The sync queue driver rethrows after invoking the chain's catch handler.
    }

    $scan = Scan::query()->firstOrFail();
    expect($scan->status)->toBe(ScanStatus::Failed)
        ->and($scan->error_message)->toContain('too many files for GitHub')
        ->and($scan->finished_at)->not->toBeNull()
        ->and(is_dir($this->storage.'/'.$scan->uuid))->toBeFalse();
    Event::assertDispatched(ScanFailed::class);
});

test('an unexpected exception is reported with a generic message', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
    $this->source->entries = [['path' => 'a.php', 'mode' => '100644', 'type' => 'blob', 'sha' => 'missing', 'size' => 1]];

    try {
        app(ScanDispatcher::class)->dispatch($this->repository);
    } catch (RuntimeException) {
    }

    expect(Scan::query()->firstOrFail()->error_message)->toBe('The scan failed unexpectedly. Please try again later.');
});

test('only one scan per repository may be active at a time', function () {
    Scan::factory()->for($this->repository)->create(['status' => ScanStatus::Analysing]);

    expect(fn () => app(ScanDispatcher::class)->dispatch($this->repository))->toThrow(ScanAlreadyRunningException::class);
});

test('the synthesis model is validated against the allowed list and stored per scan', function () {
    config()->set('sentinel.synthesis.models', ['claude-sonnet-5', 'claude-opus-5']);
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);

    expect(fn () => app(ScanDispatcher::class)->dispatch($this->repository, null, 'gpt-nope'))->toThrow(InvalidArgumentException::class);

    $scan = app(ScanDispatcher::class)->dispatch($this->repository, null, 'claude-opus-5');
    expect($scan->llm_model)->toBe('claude-opus-5');
});

test('the artisan command queues a scan for an installed repository', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);

    $this->artisan('sentinel:scan', ['repository' => 'acme/laravel-basic'])->assertSuccessful();
    $this->artisan('sentinel:scan', ['repository' => 'acme/unknown'])->assertFailed();

    expect(Scan::query()->count())->toBe(1);
});
