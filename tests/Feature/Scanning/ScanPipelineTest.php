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
use App\Scanning\Contracts\LlmClient;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Exceptions\SynthesisException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\ScanDispatcher;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\Support\FakeContentSource;
use Tests\Support\FakeLlmClient;

beforeEach(function () {
    $base = sys_get_temp_dir().'/sentinel-tests/pipeline-'.bin2hex(random_bytes(4));
    config()->set('sentinel.scan_storage_path', $base);
    app()->forgetInstance(ScanWorkspaceFactory::class);
    $this->storage = $base;

    $this->repository = Repository::factory()->for(Installation::factory()->for(User::factory()))->create(['full_name' => 'acme/laravel-basic', 'default_branch' => null]);
    $this->source = FakeContentSource::fromDirectory(fixturePath('laravel-basic'));
    $this->source->languages = ['PHP' => 9000, 'Blade' => 500];
    // The review reply, then one body per phase in the outline.
    Prism::fake([
        StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan()),
        ...array_map(fn (int $n) => StructuredResponseFake::make()->withStructured(['body' => "Run the full test suite first and confirm it is green. Do phase {$n} things: fix a.php:3 as the finding describes, apply the same pattern wherever it recurs, keep commits small with clear messages, and run the tests again before you finish. Stop if anything fails. Make no unrelated changes beyond this scope."]), [1, 2, 3, 4]),
    ]);

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
        // The binary is skipped by extension during fetch planning (not_analysed); the generated and minified files by pattern.
        ->and($scan->skipped_files['counts'])->toBe(['generated' => 1, 'minified' => 1, 'not_analysed' => 1])
        ->and($scan->slop_score)->toBeInt()
        ->and($scan->lines_of_code)->toBeGreaterThan(0)
        ->and($scan->suppression_count)->toBe(0)
        ->and($scan->prompts()->count())->toBe(8)
        ->and($scan->assessment['strengths'])->toHaveCount(2)
        ->and($scan->profile['summary']['source_files'])->toBeGreaterThan(0)
        ->and($scan->rulesFiles()->pluck('filename')->all())->toBe(['CLAUDE.md', '.cursor/rules/sentinel-slop.mdc'])
        ->and($scan->synthesis_error)->toBeNull()
        ->and($scan->synthesis_payload['usage']['finish_reason'] ?? null)->toBe('stop')
        ->and($scan->started_at)->not->toBeNull()
        ->and($scan->finished_at)->not->toBeNull()
        ->and($this->repository->fresh()->default_branch)->toBe('main')
        ->and(is_dir($this->storage.'/'.$scan->uuid))->toBeFalse();

    $statuses = collect(Event::dispatched(ScanProgressed::class))->map(fn (array $args) => $args[0]->scan->status->value)->unique()->values()->all();
    expect($statuses)->toBe(['fetching', 'preflight', 'detecting', 'analysing', 'heuristics', 'profiling', 'normalising', 'scoring', 'synthesising', 'complete']);
    Event::assertDispatched(ScanCompleted::class);
    Event::assertNotDispatched(ScanFailed::class);
});

test('a limit failure marks the scan failed with the user-facing reason and cleans up', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
    config()->set('sentinel.limits.max_file_count', 2);

    try {
        app(ScanDispatcher::class)->dispatch($this->repository);
    } catch (FetchLimitExceededException) {
        // The sync queue driver rethrows after invoking the chain's catch handler.
    }

    $scan = Scan::query()->firstOrFail();
    expect($scan->status)->toBe(ScanStatus::Failed)
        ->and($scan->error_message)->toContain('more than 2 files')
        ->and($scan->finished_at)->not->toBeNull()
        ->and(is_dir($this->storage.'/'.$scan->uuid))->toBeFalse();
    Event::assertDispatched(ScanFailed::class);
});

test('an unexpected exception is reported with a generic message', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
    $this->source->failWith = new RuntimeException('codeload hung up');

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

test('a failed prompt synthesis keeps the scan results and records the reason', function () {
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
    app()->instance(LlmClient::class, new FakeLlmClient(new SynthesisException('LLM request failed: timeout')));

    $scan = app(ScanDispatcher::class)->dispatch($this->repository);
    $scan->refresh();

    expect($scan->status)->toBe(ScanStatus::Complete)
        ->and($scan->slop_score)->toBeInt()
        ->and($scan->prompts()->count())->toBe(0)
        ->and($scan->synthesis_error)->toContain('Prompt generation failed');
});
