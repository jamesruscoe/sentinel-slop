<?php

use App\Scanning\Data\Stack;
use App\Scanning\Detect\StackDetector;
use App\Scanning\Support\FileWalker;

function treePathsFor(string $fixture): array
{
    return array_column(FileWalker::walk(fixturePath($fixture)), 'path');
}

test('it detects a Laravel project from composer.json and config presence', function () {
    $stack = (new StackDetector)->detect(fixturePath('laravel-basic'), ['PHP' => 8000, 'Blade' => 1000, 'JavaScript' => 500], [...treePathsFor('laravel-basic'), 'composer.lock']);

    expect($stack->primaryLanguage())->toBe('PHP')
        ->and($stack->hasPhp())->toBeTrue()
        ->and($stack->hasJavaScript())->toBeTrue()
        ->and($stack->hasTypeScript())->toBeFalse()
        ->and($stack->frameworks)->toBe(['laravel', 'livewire'])
        ->and($stack->tooling)->toContain('phpstan', 'larastan', 'pint', 'pest', 'phpunit', 'editorconfig', 'github-actions', 'tailwind', 'vite')
        ->and($stack->manifests)->toBe(['composer.json', 'package.json'])
        ->and($stack->packageManagers)->toBe(['composer', 'npm'])
        ->and($stack->dependencies['composer'])->toContain('laravel/framework', 'larastan/larastan')
        ->and($stack->dependencies['npm'])->toContain('axios')
        ->and($stack->languagePercentages()['PHP'])->toBe(84.2);
});

test('it detects a TypeScript React/Next project', function () {
    $stack = (new StackDetector)->detect(fixturePath('ts-react'), ['TypeScript' => 5000], [...treePathsFor('ts-react'), 'pnpm-lock.yaml']);

    expect($stack->hasPhp())->toBeFalse()
        ->and($stack->hasTypeScript())->toBeTrue()
        ->and($stack->frameworks)->toBe(['next', 'react'])
        ->and($stack->tooling)->toContain('typescript', 'eslint', 'jest', 'prettier')
        ->and($stack->packageManagers)->toBe(['npm', 'pnpm']);
});

test('it records Python frameworks and tooling as data without evaluating anything', function () {
    $stack = (new StackDetector)->detect(fixturePath('python-app'), ['Python' => 300], treePathsFor('python-app'));

    expect($stack->frameworks)->toBe(['django'])
        ->and($stack->tooling)->toBe(['mypy', 'pytest', 'ruff'])
        ->and($stack->dependencies['pip'])->toContain('django', 'requests', 'gunicorn', 'pytest')
        ->and($stack->packageManagers)->toBe(['pip']);
});

test('a broken or oversized manifest is ignored rather than failing', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/composer.json', '{not json');
    file_put_contents($workspace->repoPath().'/package.json', str_repeat(' ', 600 * 1024));

    $stack = (new StackDetector)->detect($workspace->repoPath(), [], ['composer.json', 'package.json']);

    expect($stack->manifests)->toBe(['composer.json'])
        ->and($stack->frameworks)->toBe([])
        ->and($stack->dependencies)->toBe(['composer' => []]);
});

test('stack round-trips through arrays', function () {
    $stack = (new StackDetector)->detect(fixturePath('laravel-basic'), ['PHP' => 10], treePathsFor('laravel-basic'));

    expect(Stack::fromArray($stack->toArray())->toArray())->toBe($stack->toArray());
});
