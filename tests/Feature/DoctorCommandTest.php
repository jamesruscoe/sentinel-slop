<?php

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\ProcessResult;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Process\ToolLocator;
use Composer\InstalledVersions;

/**
 * @param  array<string, string>  $versions  tool => what `--version` prints
 * @param  list<string>  $missing
 */
function fakeTools(array $versions, array $missing = []): void
{
    // ToolLocator is final and only checks that configured paths exist, so every system binary is pointed at a
    // distinct existing file and the fake runner recognises the tool from the command it is asked to run.
    $paths = ['semgrep' => PHP_BINARY, 'gitleaks' => base_path('artisan'), 'ruff' => base_path('composer.json')];
    foreach ($paths as $tool => $path) {
        config()->set("sentinel.tools.{$tool}", in_array($tool, $missing, true) ? base_path('nope/'.$tool) : $path);
    }
    app()->forgetInstance(ToolLocator::class);
    app()->instance(ToolLocator::class, new ToolLocator((array) config('sentinel.tools')));

    app()->instance(ProcessRunner::class, new class($versions) implements ProcessRunner
    {
        public function __construct(private array $versions) {}

        public function run(ProcessSpec $spec): ProcessResult
        {
            $command = implode(' ', $spec->command);
            $tool = match (true) {
                str_contains($command, 'phpstan') => 'phpstan',
                str_contains($command, 'pint') => 'pint',
                str_contains($command, 'eslint') => 'eslint',
                str_contains($command, 'jscpd') => 'jscpd',
                str_contains($command, 'artisan') => 'gitleaks',
                str_contains($command, 'composer.json') => 'ruff',
                default => 'semgrep',
            };

            return new ProcessResult(0, ($this->versions[$tool] ?? '')."\n", '');
        }
    });
}

function matchingVersions(): array
{
    $phpstan = ltrim((string) InstalledVersions::getPrettyVersion('phpstan/phpstan'), 'v');
    $pint = ltrim((string) InstalledVersions::getPrettyVersion('laravel/pint'), 'v');
    $eslint = json_decode((string) file_get_contents(base_path('node_modules/eslint/package.json')), true)['version'];
    $jscpd = json_decode((string) file_get_contents(base_path('node_modules/jscpd/package.json')), true)['version'];

    return [
        'phpstan' => "PHPStan - PHP Static Analysis Tool {$phpstan}",
        'pint' => "Pint \e[32m{$pint}\e[39m",
        'eslint' => "v{$eslint}",
        'jscpd' => "jscpd {$jscpd}",
        'semgrep' => (string) config('sentinel.tools.semgrep_version'),
        'gitleaks' => (string) config('sentinel.tools.gitleaks_version'),
        'ruff' => 'ruff '.config('sentinel.tools.ruff_version'),
    ];
}

beforeEach(function () {
    config()->set('sentinel.tools.required_extensions', ['json']);
    config()->set('sentinel.tools.worker_memory_limit', '1M');
});

test('every tool at its pinned version passes, strictly too', function () {
    fakeTools(matchingVersions());

    $this->artisan('sentinel:doctor')->assertSuccessful();
    $this->artisan('sentinel:doctor', ['--strict' => true])->assertSuccessful();
});

test('a version off its pin is reported normally and fails the strict build', function () {
    fakeTools(['ruff' => 'ruff 0.1.0'] + matchingVersions());

    $this->artisan('sentinel:doctor')->expectsOutputToContain('pinned '.config('sentinel.tools.ruff_version'))->assertSuccessful();
    $this->artisan('sentinel:doctor', ['--strict' => true])->expectsOutputToContain('ruff is ruff 0.1.0, pinned')->assertFailed();
});

test('a missing tool fails in both modes and a missing extension or small memory_limit only strictly', function () {
    fakeTools(matchingVersions(), missing: ['gitleaks']);

    $this->artisan('sentinel:doctor')->expectsOutputToContain('gitleaks is missing')->assertFailed();

    fakeTools(matchingVersions());
    config()->set('sentinel.tools.required_extensions', ['json', 'no_such_extension']);

    $this->artisan('sentinel:doctor')->assertSuccessful();
    $this->artisan('sentinel:doctor', ['--strict' => true])->expectsOutputToContain('ext-no_such_extension: not loaded')->assertFailed();

    config()->set('sentinel.tools.required_extensions', ['json']);
    config()->set('sentinel.tools.worker_memory_limit', '900G');

    $this->artisan('sentinel:doctor')->assertSuccessful();
    $this->artisan('sentinel:doctor', ['--strict' => true])->expectsOutputToContain('the worker needs at least 900G')->assertFailed();
})->skip(ini_get('memory_limit') === '-1', 'memory_limit is unlimited in this PHP, so the small-limit branch cannot be exercised');
