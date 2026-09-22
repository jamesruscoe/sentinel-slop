<?php

use App\Scanning\Contracts\Analyser;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\PreflightConfig;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Exceptions\PreflightFailedException;
use App\Scanning\Preflight\PreflightChecker;

function preflightConfig(array $overrides = []): PreflightConfig
{
    return new PreflightConfig(...array_merge([
        'maxTotalBytes' => 10 * 1024 * 1024,
        'maxFileCount' => 1000,
        'maxSingleFileBytes' => 512 * 1024,
        'skippedDirectories' => ['vendor', 'node_modules', 'dist', 'build'],
        'generatedFilePatterns' => ['*.min.js', '*.min.css', '*.lock', 'package-lock.json'],
    ], $overrides));
}

function reasonsByPath(array $skipped): array
{
    $out = [];
    foreach ($skipped as $file) {
        $out[$file->path] = $file->reason;
    }
    ksort($out);

    return $out;
}

test('binaries, minified and generated files are removed and recorded', function () {
    $workspace = workspaceFromFixture('laravel-basic');

    $result = (new PreflightChecker)->check($workspace, preflightConfig());

    expect(reasonsByPath($result->skipped))->toBe([
        'public/generated/api-client.js' => SkipReason::Generated,
        'public/logo.png' => SkipReason::Binary,
        'resources/js/vendor-bundle.min.js' => SkipReason::Minified,
    ])
        ->and($result->files)->toContain('app/Models/User.php', 'composer.json')
        ->and(file_exists($workspace->repoPath().'/public/logo.png'))->toBeFalse()
        ->and(file_exists($workspace->repoPath().'/app/Models/User.php'))->toBeTrue()
        ->and($result->totalBytes)->toBeGreaterThan(0);
});

test('native executables are detected by magic bytes, not extension', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/tool.txt', "\x7fELF\x02\x01\x01".str_repeat("\0", 20));
    file_put_contents($workspace->repoPath().'/setup.exe', "MZ\x90\x00".str_repeat("\0", 20));
    file_put_contents($workspace->repoPath().'/script.sh', "#!/bin/sh\necho hi\n");

    $result = (new PreflightChecker)->check($workspace, preflightConfig());

    expect(reasonsByPath($result->skipped))->toBe(['setup.exe' => SkipReason::Executable, 'tool.txt' => SkipReason::Executable])
        ->and($result->files)->toBe(['script.sh']);
});

test('dependency directories and oversized files on disk are removed defensively', function () {
    $workspace = workspaceFromFixture('ts-react');
    mkdir($workspace->repoPath().'/node_modules/left-pad', 0700, true);
    file_put_contents($workspace->repoPath().'/node_modules/left-pad/index.js', 'module.exports = 1');
    file_put_contents($workspace->repoPath().'/big.txt', str_repeat('a', 2000));

    $result = (new PreflightChecker)->check($workspace, preflightConfig(['maxSingleFileBytes' => 1000]));

    expect(reasonsByPath($result->skipped))->toBe(['big.txt' => SkipReason::Oversized, 'node_modules/left-pad/index.js' => SkipReason::DependencyDirectory])
        ->and(file_exists($workspace->repoPath().'/node_modules/left-pad/index.js'))->toBeFalse();
});

test('a symlink inside the workspace fails the scan', function () {
    $workspace = workspaceFromFixture('ts-react');
    $link = $workspace->repoPath().'/src/link.ts';

    if (! createTestSymlink($workspace->repoPath().'/src/utils.ts', $link)) {
        test()->markTestSkipped('Symlink creation is not permitted here (enable Windows Developer Mode).');
    }

    expect(fn () => (new PreflightChecker)->check($workspace, preflightConfig()))
        ->toThrow(PreflightFailedException::class, 'symbolic link (src/link.ts)');
});

test('a symlinked directory pointing outside the workspace is neither followed nor tolerated', function () {
    $workspace = workspaceFromFixture('ts-react');
    $outside = $workspace->root.'/outside';
    mkdir($outside);
    file_put_contents($outside.'/secret.txt', 'nope');

    if (! createTestSymlink($outside, $workspace->repoPath().'/escape')) {
        test()->markTestSkipped('Symlink creation is not permitted here (enable Windows Developer Mode).');
    }

    expect(fn () => (new PreflightChecker)->check($workspace, preflightConfig()))
        ->toThrow(PreflightFailedException::class, 'symbolic link (escape)');
});

test('limits are enforced again on the files actually on disk', function () {
    $workspace = workspaceFromFixture('ts-react');

    expect(fn () => (new PreflightChecker)->check($workspace, preflightConfig(['maxFileCount' => 2])))
        ->toThrow(PreflightFailedException::class, 'more than 2 files');

    expect(fn () => (new PreflightChecker)->check($workspace, preflightConfig(['maxTotalBytes' => 10])))
        ->toThrow(PreflightFailedException::class, 'total size');
});

test('security analyser hits are reported as critical findings', function () {
    $analyser = new class implements Analyser
    {
        public function name(): string
        {
            return 'fake-malware';
        }

        public function supports(Stack $stack): bool
        {
            return true;
        }

        public function run(string $path): FindingCollection
        {
            return new FindingCollection([
                new Finding('fake-malware', 'backdoor.eval', FindingCategory::Malware, Severity::Medium, 'src/api.ts', 3, 'eval of remote input'),
            ]);
        }
    };

    $result = (new PreflightChecker([$analyser]))->check(workspaceFromFixture('ts-react'), preflightConfig());

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings->all()[0]->severity)->toBe(Severity::Critical)
        ->and($result->findings->all()[0]->category)->toBe(FindingCategory::Malware);
});
