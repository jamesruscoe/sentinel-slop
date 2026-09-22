<?php

use App\Scanning\Data\FetchLimits;
use App\Scanning\Data\RepositoryRef;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Fetch\GitHubTreeFetcher;
use Tests\Support\FakeContentSource;

function fetchLimits(array $overrides = []): FetchLimits
{
    return new FetchLimits(...array_merge([
        'maxTotalBytes' => 10 * 1024 * 1024,
        'maxFileCount' => 1000,
        'maxSingleFileBytes' => 512 * 1024,
        'skippedDirectories' => ['vendor', 'node_modules', 'dist', 'build'],
    ], $overrides));
}

function skippedReasons(array $skipped): array
{
    $out = [];
    foreach ($skipped as $file) {
        $out[$file->path] = $file->reason;
    }

    return $out;
}

test('it downloads regular files from the tree into the workspace', function () {
    $source = FakeContentSource::fromDirectory(fixturePath('laravel-basic'));
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(RepositoryRef::fromFullName('acme/laravel-basic'), $workspace, fetchLimits());

    expect($result->commitSha)->toBe($source->headSha)
        ->and($result->files)->toContain('app/Models/User.php', 'composer.json', 'public/logo.png')
        ->and($result->treePaths)->toBe(array_column($source->entries, 'path'))
        ->and(file_get_contents($workspace->repoPath().'/routes/web.php'))->toContain('HomeController')
        ->and($source->log)->toContain('repository acme/laravel-basic', 'head main');
});

test('it uses the given branch instead of asking GitHub for the default', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'a');

    (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('acme', 'repo', 'develop'), temporaryWorkspace(), fetchLimits());

    expect($source->log)->toContain('head develop')->not->toContain('repository acme/repo');
});

test('symlinks, submodules and odd modes are recorded and never written', function () {
    $source = (new FakeContentSource)
        ->addFile('app.php', '<?php')
        ->addSymlink('link-to-etc', '/etc/passwd')
        ->addSubmodule('lib/dep')
        ->addTree('app')
        ->addFile('weird', 'x', mode: '100600');
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits());

    expect($result->files)->toBe(['app.php'])
        ->and(skippedReasons($result->skipped))->toBe(['link-to-etc' => SkipReason::Symlink, 'lib/dep' => SkipReason::Submodule, 'weird' => SkipReason::UnsupportedMode])
        ->and(file_exists($workspace->repoPath().'/link-to-etc'))->toBeFalse()
        ->and(is_link($workspace->repoPath().'/link-to-etc'))->toBeFalse()
        ->and($source->blobRequests)->toBe(1);
});

test('dependency directories are skipped without downloading a single blob', function () {
    $source = (new FakeContentSource)
        ->addFile('src/index.js', 'ok')
        ->addFile('vendor/autoload.php', '<?php evil();')
        ->addFile('vendor/composer/ClassLoader.php', '<?php')
        ->addFile('packages/ui/node_modules/left-pad/index.js', 'module.exports = 1');
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits());

    expect($result->files)->toBe(['src/index.js'])
        ->and($source->blobRequests)->toBe(1)
        ->and(is_dir($workspace->repoPath().'/vendor'))->toBeFalse()
        ->and(skippedReasons($result->skipped))->toBe(['vendor/' => SkipReason::DependencyDirectory, 'packages/ui/node_modules/' => SkipReason::DependencyDirectory])
        ->and($result->skipped[0]->detail)->toBe('2 files not downloaded');
});

test('progress is reported while downloading', function () {
    $source = new FakeContentSource;
    foreach (range(1, 30) as $i) {
        $source->addFile("f{$i}.txt", 'x');
    }
    $calls = [];

    (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), temporaryWorkspace(), fetchLimits(), function (int $done, int $total) use (&$calls) {
        $calls[] = [$done, $total];
    });

    expect($calls)->toBe([[25, 30], [30, 30]]);
});
