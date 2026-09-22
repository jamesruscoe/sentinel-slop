<?php

use App\Scanning\Analysers\EslintAnalyser;
use App\Scanning\Analysers\JscpdAnalyser;
use App\Scanning\Analysers\SemgrepAnalyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Process\ToolLocator;

beforeEach(function () {
    $this->workspace = workspaceFromFixture('sloppy-ts');
});

test('eslint reports slop with non-type-aware rules and ignores inline disables', function () {
    $findings = app(EslintAnalyser::class)->run($this->workspace->repoPath());
    $rules = array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all()));
    $byFile = fn (string $file) => array_values(array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, array_filter($findings->all(), fn (Finding $f) => $f->filePath === $file))));

    expect($rules)->toContain('eqeqeq', 'no-var', 'no-empty', '@typescript-eslint/no-explicit-any', '@typescript-eslint/no-unused-vars', 'max-lines-per-function')
        ->and($byFile('src/disabled.ts'))->toContain('eqeqeq', 'no-var')
        ->and($byFile('src/long.ts'))->toContain('max-lines-per-function')
        ->and(collect($findings->all())->first(fn (Finding $f) => $f->ruleId === 'max-lines-per-function')->category)->toBe(FindingCategory::Complexity);
});

test('jscpd finds the duplicated address normaliser', function () {
    $findings = app(JscpdAnalyser::class)->run($this->workspace->repoPath());

    expect($findings)->toHaveCount(1)
        ->and($findings->all()[0]->category)->toBe(FindingCategory::Duplication)
        ->and($findings->all()[0]->filePath)->toBe('src/dupB.ts')
        ->and($findings->all()[0]->message)->toContain('src/dupA.ts');
});

test('the semgrep slop rules catch swallowed errors, stubs and placeholder data in TypeScript', function () {
    $semgrep = new SemgrepAnalyser(app(ProcessRunner::class), app(ToolLocator::class), app(AnalyserOptions::class), 'slop', 'semgrep-slop');
    $findings = $semgrep->run($this->workspace->repoPath());
    $rules = array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all()));

    expect($rules)->toContain(
        'sentinel.slop.js.empty-catch',
        'sentinel.slop.js.catch-only-logs',
        'sentinel.slop.js.not-implemented-stub',
        'sentinel.slop.js.placeholder-data',
    )->and(collect($findings->all())->first(fn (Finding $f) => $f->ruleId === 'sentinel.slop.js.empty-catch')->category)->toBe(FindingCategory::ErrorHandling);
});
