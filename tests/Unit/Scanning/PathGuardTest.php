<?php

use App\Scanning\Exceptions\UnsafePathException;
use App\Scanning\Support\PathGuard;
use App\Scanning\Support\PathMatcher;

test('safe relative paths pass through unchanged', function (string $path) {
    expect(PathGuard::assertSafeRelativePath($path))->toBe($path);
})->with(['app/Models/User.php', 'README.md', '.github/workflows/ci.yml', 'src/deep/nested/file.test.ts', 'weird name with spaces.txt']);

test('unsafe paths are rejected', function (string $path) {
    expect(fn () => PathGuard::assertSafeRelativePath($path))->toThrow(UnsafePathException::class);
})->with([
    'parent escape' => '../outside.php',
    'nested escape' => 'app/../../outside.php',
    'absolute unix' => '/etc/passwd',
    'absolute windows' => 'C:/Windows/system.ini',
    'backslash' => 'app\..\evil.php',
    'nul byte' => "app/x\0.php",
    'dot segment' => 'app/./x.php',
    'empty segment' => 'app//x.php',
    'git dir' => '.git/config',
    'colon' => 'app:evil.php',
    'empty' => '',
]);

test('isInside compares resolved paths', function () {
    $workspace = temporaryWorkspace();
    $inside = $workspace->repoPath().'/a.txt';
    file_put_contents($inside, 'x');
    $outside = $workspace->root.'/outside.txt';
    file_put_contents($outside, 'x');

    expect(PathGuard::isInside($workspace->repoPath(), $inside))->toBeTrue()
        ->and(PathGuard::isInside($workspace->repoPath(), $outside))->toBeFalse()
        ->and(PathGuard::isInside($workspace->repoPath(), $workspace->repoPath().'/missing.txt'))->toBeFalse();
});

test('skipped directories match by segment or sub-path', function () {
    $skipped = ['vendor', 'node_modules', 'storage/framework'];

    expect(PathMatcher::skippedDirectoryFor('vendor/autoload.php', $skipped))->toBe('vendor')
        ->and(PathMatcher::skippedDirectoryFor('packages/foo/node_modules/x/index.js', $skipped))->toBe('packages/foo/node_modules')
        ->and(PathMatcher::skippedDirectoryFor('storage/framework/views/x.php', $skipped))->toBe('storage/framework')
        ->and(PathMatcher::skippedDirectoryFor('storage/app/file.txt', $skipped))->toBeNull()
        ->and(PathMatcher::skippedDirectoryFor('app/vendor.php', $skipped))->toBeNull()
        ->and(PathMatcher::skippedDirectoryFor('vendor', $skipped))->toBeNull();
});

test('basename globs support * and ?', function () {
    expect(PathMatcher::matchesGlob('app.min.js', '*.min.js'))->toBeTrue()
        ->and(PathMatcher::matchesGlob('APP.MIN.JS', '*.min.js'))->toBeTrue()
        ->and(PathMatcher::matchesGlob('app.js', '*.min.js'))->toBeFalse()
        ->and(PathMatcher::matchesGlob('eslint.config.mjs', 'eslint.config.*'))->toBeTrue()
        ->and(PathMatcher::matchesGlob('jest.config.ts', 'jest.config.?s'))->toBeTrue();
});
