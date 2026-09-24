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

test('jscpd compares Vue single-file components whole, so page-level duplication is found', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/resources/js/pages/Staff/Dogs', 0777, true);
    @mkdir($workspace->repoPath().'/resources/js/pages/Owner/Dogs', 0777, true);
    $script = "<script setup lang=\"ts\">\n";
    for ($i = 1; $i <= 12; $i++) {
        $script .= "const filtered{$i} = computed(() => props.dogs.filter((dog) => dog.name.toLowerCase().includes(search.value.toLowerCase()) && dog.id !== {$i}))\n";
    }
    $script .= "</script>\n<template>\n";
    for ($i = 1; $i <= 6; $i++) {
        $script .= "  <li v-for=\"dog in filtered{$i}\" :key=\"dog.id\" class=\"flex items-center justify-between py-2\"><span>{{ dog.name }} ({{ dog.breed }})</span><button type=\"button\" @click=\"remove(dog.id)\">Remove {$i}</button></li>\n";
    }
    $script .= "</template>\n";
    file_put_contents($workspace->repoPath().'/resources/js/pages/Staff/Dogs/Index.vue', $script);
    file_put_contents($workspace->repoPath().'/resources/js/pages/Owner/Dogs/Index.vue', $script);

    $findings = app(JscpdAnalyser::class)->run($workspace->repoPath());

    expect($findings)->toHaveCount(1)
        ->and($findings->all()[0]->filePath)->toBe('resources/js/pages/Staff/Dogs/Index.vue')
        ->and($findings->all()[0]->message)->toContain('resources/js/pages/Owner/Dogs/Index.vue')
        ->and($findings->all()[0]->filePath)->not->toContain(':');
});

test('jscpd ignores every .gitignore, including one in a parent directory that excludes the workspace', function () {
    // Real scans live under storage/app/scans, which Sentinel Slop's own .gitignore excludes; jscpd 5 walks parent
    // .gitignore files and used to analyse nothing. A repository's own .gitignore must not hide files either.
    $workspace = temporaryWorkspace();
    file_put_contents(dirname($workspace->root).'/.gitignore', "*\n");
    file_put_contents($workspace->repoPath().'/.gitignore', "src/\n*.ts\n");
    @mkdir($workspace->repoPath().'/src', 0777, true);
    $body = '';
    for ($i = 1; $i <= 14; $i++) {
        $body .= "export function normalise{$i}(input: string): string {\n    return input.trim().toLowerCase().replace(/\\s+/g, ' ').replace(/[^a-z0-9 ]/g, '') + '{$i}'\n}\n";
    }
    file_put_contents($workspace->repoPath().'/src/dupA.ts', $body);
    file_put_contents($workspace->repoPath().'/src/dupB.ts', $body);

    try {
        $findings = app(JscpdAnalyser::class)->run($workspace->repoPath());
    } finally {
        @unlink(dirname($workspace->root).'/.gitignore');
    }

    expect($findings)->toHaveCount(1)->and($findings->all()[0]->message)->toContain('src/dupA.ts');
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
