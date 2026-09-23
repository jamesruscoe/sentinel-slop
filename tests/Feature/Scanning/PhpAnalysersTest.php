<?php

use App\Scanning\Analysers\GitleaksAnalyser;
use App\Scanning\Analysers\PhpStanAnalyser;
use App\Scanning\Analysers\PintAnalyser;
use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;

beforeEach(function () {
    $this->workspace = workspaceFromFixture('sloppy-laravel');
});

test('phpstan reports real type problems without drowning in unknown-class noise', function () {
    $findings = app(PhpStanAnalyser::class)->run($this->workspace->repoPath());
    $identifiers = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());
    $files = array_unique(array_map(fn (Finding $f) => $f->filePath, $findings->all()));

    expect($identifiers)->toContain('return.type')
        ->and($identifiers)->not->toContain('class.notFound', 'method.notFound', 'staticMethod.notFound', 'property.notFound')
        ->and($findings->count())->toBeLessThan(25)
        ->and($files)->not->toContain('resources/views/order.blade.php')
        ->and($findings->all()[0]->category)->toBe(FindingCategory::TypeSafety);
});

test('pint reports files that differ from the laravel preset', function () {
    $findings = app(PintAnalyser::class)->run($this->workspace->repoPath());
    $files = array_map(fn (Finding $f) => $f->filePath, $findings->all());

    expect($files)->toContain('app/Models/Order.php')
        ->and($files)->not->toContain('resources/views/order.blade.php')
        ->and($findings->all()[0]->category)->toBe(FindingCategory::Style);
});

test('gitleaks finds a hard-coded api key and reports only its type and location', function () {
    $findings = app(GitleaksAnalyser::class)->run($this->workspace->repoPath());
    $hit = collect($findings->all())->first(fn (Finding $f) => $f->filePath === 'app/Services/PaymentClient.php');

    expect($hit)->not->toBeNull()
        ->and($hit->category)->toBe(FindingCategory::Secrets)
        ->and($hit->line)->toBe(7)
        ->and($hit->snippet)->toBeNull()
        ->and($hit->message)->not->toContain('Qm7xLp2Z');
});

test('phpstan drops non-ignorable unknown-symbol errors when the symbol resolves to a package in composer.lock', function () {
    $workspace = workspaceFromFixture('lockfile-deps');
    $findings = app(PhpStanAnalyser::class)->run($workspace->repoPath());
    $identifiers = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());
    $messages = implode("\n", array_map(fn (Finding $f) => $f->message, $findings->all()));

    expect($identifiers)->not->toContain('class.notFound', 'interface.notFound', 'trait.notFound', 'class.noParent')
        ->and($messages)->not->toContain('Inertia\Middleware', 'Spatie\MediaLibrary', 'Tighten\Ziggy');
});
