<?php

use App\Scanning\Data\Finding;
use App\Scanning\Detect\DependencyIndex;
use App\Scanning\Heuristics\HallucinatedDependenciesHeuristic;
use App\Scanning\Score\LinesOfCodeCounter;

function depFindings(string $path): array
{
    $findings = (new HallucinatedDependenciesHeuristic)->run($path)->all();
    usort($findings, fn (Finding $a, Finding $b) => [$a->filePath, $a->line] <=> [$b->filePath, $b->line]);

    return array_map(fn (Finding $f) => [$f->ruleId, $f->severity->value, $f->filePath.':'.$f->line, $f->message], $findings);
}

test('composer.lock autoload maps resolve namespaces to packages whose names look nothing like them', function () {
    $index = DependencyIndex::build(fixturePath('lockfile-deps'));

    expect($index->hasComposerLock)->toBeTrue()
        ->and($index->resolvePhp('Inertia\Middleware'))->toBe('inertiajs/inertia-laravel')
        ->and($index->resolvePhp('Spatie\MediaLibrary\HasMedia'))->toBe('spatie/laravel-medialibrary')
        ->and($index->resolvePhp('Spatie\Image\Enums\Fit'))->toBe('spatie/image')
        ->and($index->resolvePhp('Tighten\Ziggy\Ziggy'))->toBe('tightenco/ziggy')
        ->and($index->resolvePhp('\Illuminate\Database\Eloquent\Model'))->toBe('laravel/framework')
        ->and($index->resolvePhp('Acme\Fake\Thing'))->toBeNull()
        ->and($index->composerDeclares('inertiajs/inertia-laravel'))->toBeTrue()
        ->and($index->composerDeclares('spatie/image'))->toBeFalse()
        ->and($index->hasNpmLock)->toBeTrue()
        ->and($index->npmInstalled('axios'))->toBeTrue()
        ->and($index->npmInstalled('@vue/server-renderer'))->toBeTrue()
        ->and($index->npmInstalled('esbuild'))->toBeTrue()
        ->and($index->npmInstalled('left-pad-fake'))->toBeFalse()
        ->and($index->npmDeclares('vue'))->toBeTrue()
        ->and($index->npmDeclares('axios'))->toBeFalse();
});

test('the three outcomes are reported separately and installed-and-declared imports produce nothing', function () {
    $findings = depFindings(fixturePath('lockfile-deps'));

    expect($findings)->toBe([
        ['hallucinated-php-namespace', 'medium', 'app/Models/CareLog.php:5', 'Namespace Acme\Fake is not provided by any package in composer.lock; the import may be hallucinated.'],
        ['undeclared-transitive-php-package', 'low', 'app/Models/CareLog.php:7', 'Spatie\Image\Enums\Fit is provided by spatie/image, which is not in composer.json (it is installed because spatie/laravel-medialibrary requires it); declare it explicitly.'],
        ['undeclared-transitive-npm-package', 'low', 'resources/js/app.js:2', 'Package "axios" is imported and installed, but only as a transitive dependency; declare it explicitly in package.json.'],
        ['undeclared-transitive-npm-package', 'low', 'resources/js/app.js:3', 'Package "@vue/server-renderer" is imported and installed, but only as a transitive dependency; declare it explicitly in package.json.'],
        ['hallucinated-npm-package', 'medium', 'resources/js/app.js:4', 'Package "left-pad-fake" is imported but neither declared in package.json nor present in the lockfile; the import may be hallucinated.'],
    ]);

    $hallucinated = array_filter($findings, fn (array $f) => $f[0] === 'hallucinated-php-namespace');
    expect($hallucinated)->toHaveCount(1);
});

test('types the framework hands out through its own API are not transitive findings while the framework is declared', function () {
    $index = DependencyIndex::build(fixturePath('lockfile-deps'));

    expect($index->resolvePhp('Carbon\Carbon'))->toBe('nesbot/carbon')
        ->and($index->composerDeclares('nesbot/carbon'))->toBeFalse()
        ->and($index->composerRequiredBy('spatie/image'))->toBe('spatie/laravel-medialibrary')
        ->and($index->composerRequiredBy('nesbot/carbon'))->toBe('laravel/framework')
        ->and(array_filter(depFindings(fixturePath('lockfile-deps')), fn (array $f) => str_contains($f[3], 'Carbon') || str_contains($f[3], 'Symfony')))->toBe([]);
});

test('the dog-kennel false positives: Inertia, Spatie\MediaLibrary and Tighten resolve to their real packages', function () {
    $index = DependencyIndex::build(fixturePath('lockfile-deps'));

    foreach (['Inertia\Middleware' => 'inertiajs/inertia-laravel', 'Spatie\MediaLibrary\InteractsWithMedia' => 'spatie/laravel-medialibrary', 'Tighten\Ziggy\Ziggy' => 'tightenco/ziggy'] as $fqcn => $package) {
        expect($index->resolvePhp($fqcn))->toBe($package)->and($index->composerDeclares($package))->toBeTrue();
    }

    expect(array_filter(depFindings(fixturePath('lockfile-deps')), fn (array $f) => str_contains($f[3], 'Inertia') || str_contains($f[3], 'MediaLibrary') || str_contains($f[3], 'Tighten')))->toBe([]);
});

test('without a lockfile the severity drops and the finding says it could not verify', function () {
    $findings = depFindings(fixturePath('sloppy-laravel'));
    $acme = array_values(array_filter($findings, fn (array $f) => str_contains($f[3], 'Acme')));

    expect($acme)->toHaveCount(1)
        ->and($acme[0][0])->toBe('unverifiable-php-namespace')
        ->and($acme[0][1])->toBe('low')
        ->and($acme[0][3])->toContain('no composer.lock');

    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/package.json', '{"dependencies": {"vue": "^3"}}');
    file_put_contents($workspace->repoPath().'/app.js', "import axios from 'axios';\nimport { createApp } from 'vue';\n");
    $js = depFindings($workspace->repoPath());

    expect($js)->toHaveCount(1)
        ->and($js[0][0])->toBe('unverifiable-npm-package')
        ->and($js[0][1])->toBe('low')
        ->and($js[0][3])->toContain('no lockfile');
});

test('yarn and pnpm lockfiles are read for installed packages', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/package.json', '{"dependencies": {"vue": "^3"}}');
    file_put_contents($workspace->repoPath().'/yarn.lock', "# yarn lockfile v1\n\n\"@vue/server-renderer@^3.5.0\":\n  version \"3.5.13\"\n\naxios@^1.7.0, axios@^1.6.0:\n  version \"1.7.9\"\n\nvue@^3:\n  version \"3.5.13\"\n");
    $yarn = DependencyIndex::build($workspace->repoPath());

    expect($yarn->hasNpmLock)->toBeTrue()->and($yarn->npmInstalled('axios'))->toBeTrue()->and($yarn->npmInstalled('@vue/server-renderer'))->toBeTrue()->and($yarn->npmInstalled('nope'))->toBeFalse();

    unlink($workspace->repoPath().'/yarn.lock');
    file_put_contents($workspace->repoPath().'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n\npackages:\n\n  '@vue/server-renderer@3.5.13':\n    resolution: {integrity: sha512-x}\n\n  axios@1.7.9:\n    resolution: {integrity: sha512-y}\n\n  /vue@3.5.13:\n    resolution: {integrity: sha512-z}\n");
    $pnpm = DependencyIndex::build($workspace->repoPath());

    expect($pnpm->hasNpmLock)->toBeTrue()->and($pnpm->npmInstalled('axios'))->toBeTrue()->and($pnpm->npmInstalled('vue'))->toBeTrue()->and($pnpm->npmInstalled('@vue/server-renderer'))->toBeTrue();
});

test('lockfiles are recognised by basename at any depth and count as data, not code', function () {
    expect(DependencyIndex::isLockfile('composer.lock'))->toBeTrue()
        ->and(DependencyIndex::isLockfile('packages/web/package-lock.json'))->toBeTrue()
        ->and(DependencyIndex::isLockfile('Cargo.lock'))->toBeFalse()
        ->and(LinesOfCodeCounter::isCode('composer.lock'))->toBeFalse();
});
