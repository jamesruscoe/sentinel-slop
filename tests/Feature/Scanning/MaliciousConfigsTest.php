<?php

use App\Scanning\Analysers\EslintAnalyser;
use App\Scanning\Analysers\GitleaksAnalyser;
use App\Scanning\Analysers\JscpdAnalyser;
use App\Scanning\Analysers\PhpStanAnalyser;
use App\Scanning\Analysers\PintAnalyser;
use App\Scanning\Analysers\RuffAnalyser;
use App\Scanning\Analysers\SemgrepAnalyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Process\ToolLocator;

/**
 * Every config file in the malicious-configs fixture either executes code when
 * a tool loads it (writing a CANARY_* file) or suppresses findings. These tests
 * prove the bundled configs win: no canary appears and every planted finding is
 * still reported.
 */
beforeEach(function () {
    $this->workspace = workspaceFromFixture('malicious-configs');
    $repo = $this->workspace->repoPath();

    // A vendor/autoload.php can never be fetched, but if one existed it must still never be loaded.
    mkdir($repo.'/vendor', 0700, true);
    copy($repo.'/canary-bootstrap.php', $repo.'/vendor/autoload.php');

    foreach (canaryFiles($repo) as $stale) {
        @unlink($stale);
    }
});

function canaryFiles(string $repo): array
{
    $temp = sys_get_temp_dir();

    return array_values(array_filter([
        ...(glob($repo.'/CANARY_*') ?: []),
        $temp.'/sentinel-canary-php',
        $temp.'/sentinel-canary-node',
    ], 'file_exists'));
}

function rulesIn(FindingCollection $findings, string $file): array
{
    return array_values(array_unique(array_map(
        fn (Finding $f) => (string) $f->ruleId,
        array_filter($findings->all(), fn (Finding $f) => $f->filePath === $file),
    )));
}

test('phpstan ignores the repo phpstan.neon, bootstrap files and vendor/autoload.php', function () {
    $findings = app(PhpStanAnalyser::class)->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and(rulesIn($findings, 'app/Bad.php'))->toContain('return.type');
});

test('pint ignores the repo pint.json and .php-cs-fixer config', function () {
    $findings = app(PintAnalyser::class)->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and(array_map(fn (Finding $f) => $f->filePath, $findings->all()))->toContain('app/Bad.php');
});

test('eslint ignores the repo eslint configs, .eslintignore, package.json eslintConfig and inline disables', function () {
    $findings = app(EslintAnalyser::class)->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and(rulesIn($findings, 'src/bad.js'))->toContain('eqeqeq', 'no-var');
});

test('jscpd ignores the repo .jscpd.json, package.json jscpd key and custom reporter', function () {
    $findings = app(JscpdAnalyser::class)->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and($findings)->toHaveCount(1)
        ->and($findings->all()[0]->filePath)->toBe('src/dup2.js')
        ->and($findings->all()[0]->message)->toContain('src/dup1.js');
});

test('gitleaks ignores the repo .gitleaks.toml, .gitleaksignore and gitleaks:allow comments, and redacts the value', function () {
    $findings = app(GitleaksAnalyser::class)->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and(rulesIn($findings, 'app/Secrets.php'))->toContain('generic-api-key')
        ->and(json_encode($findings->toArray()))->not->toContain('Qm7xLp2Z');
});

test('ruff ignores the repo pyproject.toml, ruff.toml, .ruff.toml and noqa comments', function () {
    $findings = app(RuffAnalyser::class)->run($this->workspace->repoPath());

    // The repo's configs exclude every file and select no rules; the file carries noqa on both lines.
    expect(rulesIn($findings, 'src/bad.py'))->toContain('F401', 'E722')
        ->and(canaryFiles($this->workspace->repoPath()))->toBe([]);
});

test('semgrep ignores the repo .semgrepignore, .semgrep.yml and nosemgrep comments', function () {
    $semgrep = new SemgrepAnalyser(app(ProcessRunner::class), app(ToolLocator::class), app(AnalyserOptions::class), 'malware', 'semgrep-malware');

    $findings = $semgrep->run($this->workspace->repoPath());

    expect(canaryFiles($this->workspace->repoPath()))->toBe([])
        ->and(rulesIn($findings, 'app/Backdoor.php'))->toContain('sentinel.malware.php.eval-of-decoded-payload');
});
