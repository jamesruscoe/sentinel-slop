<?php

use App\Enums\ScanStatus;
use App\Events\ScanCompleted;
use App\Events\ScanFailed;
use App\Events\ScanProgressed;
use App\Models\Installation;
use App\Models\Repository;
use App\Models\User;
use App\Scanning\Analysers\AnalyserFailure;
use App\Scanning\Analysers\AnalyserRegistry;
use App\Scanning\Analysers\LanguageCoverage;
use App\Scanning\Contracts\Analyser;
use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Exceptions\AnalyserFailedException;
use App\Scanning\Exceptions\AnalyserTimedOutException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\ScanDispatcher;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\Support\FakeContentSource;
use Tests\Support\FakeLlmClient;

function fakeAnalyser(string $name, Closure $run): Analyser
{
    return new class($name, $run) implements Analyser
    {
        public function __construct(private string $name, private Closure $run) {}

        public function name(): string
        {
            return $this->name;
        }

        public function supports(Stack $stack): bool
        {
            return true;
        }

        public function run(string $path): FindingCollection
        {
            return ($this->run)($path);
        }
    };
}

beforeEach(function () {
    $base = sys_get_temp_dir().'/sentinel-tests/failures-'.bin2hex(random_bytes(4));
    config()->set('sentinel.scan_storage_path', $base);
    app()->forgetInstance(ScanWorkspaceFactory::class);
    $this->storage = $base;

    $this->repository = Repository::factory()->for(Installation::factory()->for(User::factory()))->create(['full_name' => 'acme/laravel-basic', 'default_branch' => 'main']);
    $source = FakeContentSource::fromDirectory(fixturePath('laravel-basic'));
    $source->languages = ['PHP' => 9000, 'JavaScript' => 500];
    app()->instance(ContentSourceResolver::class, new class($source) implements ContentSourceResolver
    {
        public function __construct(private GitHubContentSource $source) {}

        public function forRepository(Repository $repository): GitHubContentSource
        {
            return $this->source;
        }
    });
    Prism::fake([
        StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan()),
        ...array_map(fn (int $n) => StructuredResponseFake::make()->withStructured(['body' => "Run the full test suite first and confirm it is green. Do phase {$n} things: fix a.php:3 as the finding describes, apply the same pattern wherever it recurs, keep commits small with clear messages, and run the tests again before you finish. Stop if anything fails. Make no unrelated changes beyond this scope."]), [1, 2, 3, 4]),
    ]);
    Event::fake([ScanProgressed::class, ScanCompleted::class, ScanFailed::class]);
});

afterEach(function () {
    ScanWorkspace::at($this->storage)->delete();
});

test('an analyser that times out or crashes is recorded and the scan completes without it', function () {
    app()->instance(AnalyserRegistry::class, new AnalyserRegistry([
        fakeAnalyser('phpstan', fn () => throw new AnalyserTimedOutException('phpstan', 900)),
        fakeAnalyser('pint', fn () => new FindingCollection),
        fakeAnalyser('eslint', fn () => throw new AnalyserFailedException('eslint produced unreadable JSON output: boom')),
    ]));

    $scan = app(ScanDispatcher::class)->dispatch($this->repository, $this->repository->installation->user);
    $scan->refresh();

    expect($scan->status)->toBe(ScanStatus::Complete)
        ->and($scan->slop_score)->toBeInt()
        ->and($scan->prompts()->count())->toBe(8)
        ->and(array_map(fn (array $f) => [$f['tool'], $f['kind']], $scan->analyser_failures))->toBe([['phpstan', 'timeout'], ['eslint', 'error']])
        ->and($scan->analyser_failures[0]['message'])->toBe('phpstan exceeded the 900s timeout.')
        ->and($scan->synthesis_payload['user'])->toContain('PHPStan timed out after 900 s and did not run; PHP had Pint, php-parser heuristics and Semgrep PHP rules only.')
        ->and($scan->synthesis_payload['user'])->toContain('ESLint failed and did not run (eslint produced unreadable JSON output: boom); JavaScript had Semgrep JS/TS rules only.')
        ->and($scan->synthesis_payload['phase_prompts'][1])->toContain('PHPStan timed out after 900 s');
    Event::assertNotDispatched(ScanFailed::class);
    Event::assertDispatched(ScanProgressed::class, fn (ScanProgressed $e) => $e->message === 'PHPStan timed out after 900 s and did not run');

    $this->actingAs($this->repository->installation->user)->get(route('scans.show', $scan))
        ->assertOk()
        ->assertSee('Did not run:')
        ->assertSee('PHPStan timed out after 900 s and did not run');
});

test('the coverage sentence strikes a failed tool off its language and says what is left', function () {
    $stack = Stack::fromArray(['languages' => ['PHP' => 8000, 'Python' => 2000]]);
    $timeout = AnalyserFailure::from('phpstan', new AnalyserTimedOutException('phpstan', 900), 900.4);
    $crash = AnalyserFailure::from('ruff', new AnalyserFailedException('ruff produced unreadable JSON output: x'), 2.2);

    expect(LanguageCoverage::sentence($stack))->toStartWith('Language-specific analysers ran for PHP (PHPStan, Pint, php-parser heuristics, Semgrep PHP rules); Python (Ruff).')
        ->and(LanguageCoverage::sentence($stack, [$timeout, $crash]))
        ->toContain('Language-specific analysers ran for PHP (Pint, php-parser heuristics, Semgrep PHP rules).')
        ->toContain('Python had structural analysis only')
        ->toContain('PHPStan timed out after 900 s and did not run; PHP had Pint, php-parser heuristics and Semgrep PHP rules only. Its absence is not a clean result')
        ->toContain('Ruff failed and did not run (ruff produced unreadable JSON output: x); Python had no language-specific analyser.')
        ->and(AnalyserFailure::fromArray($timeout->toArray())->sentence())->toBe('PHPStan timed out after 900 s and did not run');
});
