<?php

use App\Scanning\Data\RepositoryRef;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Exceptions\UnsafePathException;
use App\Scanning\Fetch\GitHubTreeFetcher;
use Tests\Support\FakeContentSource;

test('a path escape attempt aborts the fetch before anything is downloaded', function () {
    $source = (new FakeContentSource)->addFile('ok.php', '<?php')->addFile('../../escape.php', '<?php');
    $workspace = temporaryWorkspace();

    expect(fn () => (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits()))
        ->toThrow(UnsafePathException::class);

    expect($source->blobRequests)->toBe(0)
        ->and(file_exists(dirname($workspace->root).'/escape.php'))->toBeFalse()
        ->and(file_exists($workspace->root.'/escape.php'))->toBeFalse();
});

test('too many files aborts before downloading', function () {
    $source = new FakeContentSource;
    foreach (range(1, 6) as $i) {
        $source->addFile("f{$i}.txt", 'x');
    }

    expect(fn () => (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), temporaryWorkspace(), fetchLimits(['maxFileCount' => 5])))
        ->toThrow(FetchLimitExceededException::class, 'more than 5 files');
    expect($source->blobRequests)->toBe(0);
});

test('images, catalogues and generated files are neither downloaded nor counted toward the limits', function () {
    // Django: 2,537 .po/.mo files; Filament: 112 MB of images. Neither is analysed, so neither may trip a limit.
    $source = (new FakeContentSource)->addFile('app.py', 'print(1)')->addFile('locale/de/LC_MESSAGES/django.po', 'msgid ""')->addFile('locale/de/LC_MESSAGES/django.mo', 'x', declaredSize: 4_000_000)
        ->addFile('docs/logo.png', 'x', declaredSize: 9_000_000)->addFile('public/app.min.js', 'x', declaredSize: 3_000_000);
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits(['maxFileCount' => 1, 'maxTotalBytes' => 100, 'skippedExtensions' => ['png', 'po', 'mo'], 'generatedFilePatterns' => ['*.min.js']]));

    expect($result->files)->toBe(['app.py'])
        ->and($source->blobRequests)->toBe(1)
        ->and(array_map(fn ($s) => [$s->path, $s->reason->value, $s->detail], $result->skipped))->toBe([
            ['public/app.min.js', 'minified', 'not downloaded'],
            ['*.po', 'not_analysed', '1 files not downloaded'],
            ['*.mo', 'not_analysed', '1 files not downloaded'],
            ['*.png', 'not_analysed', '1 files not downloaded'],
        ]);
});

test('declared total size over the limit aborts before downloading', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'aaaa')->addFile('b.txt', 'bbbb');

    expect(fn () => (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), temporaryWorkspace(), fetchLimits(['maxTotalBytes' => 6])))
        ->toThrow(FetchLimitExceededException::class, 'total size');
    expect($source->blobRequests)->toBe(0);
});

test('a declared oversized file is skipped without being downloaded', function () {
    $source = (new FakeContentSource)->addFile('small.txt', 'ok')->addFile('huge.bin', 'x', declaredSize: 5_000_000);
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits());

    expect($result->files)->toBe(['small.txt'])
        ->and($source->blobRequests)->toBe(1)
        ->and($result->skipped[0]->reason)->toBe(SkipReason::Oversized);
});

test('actual downloaded size is enforced even when the tree under-reports it', function () {
    $source = (new FakeContentSource)->addFile('small.txt', 'ok')->addFile('lying.bin', str_repeat('x', 100), declaredSize: 1);
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits(['maxSingleFileBytes' => 50]));

    expect($result->files)->toBe(['small.txt'])
        ->and($result->skipped[0]->reason)->toBe(SkipReason::Oversized)
        ->and(file_exists($workspace->repoPath().'/lying.bin'))->toBeFalse();

    $source = (new FakeContentSource)->addFile('a.txt', str_repeat('a', 40), declaredSize: 1)->addFile('b.txt', str_repeat('b', 40), declaredSize: 1);
    expect(fn () => (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), temporaryWorkspace(), fetchLimits(['maxTotalBytes' => 60])))
        ->toThrow(FetchLimitExceededException::class);
});

test('a truncated tree is refused', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'a');
    $source->truncated = true;

    expect(fn () => (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), temporaryWorkspace(), fetchLimits()))
        ->toThrow(FetchLimitExceededException::class, 'too many files');
});

test('case-insensitive filename collisions keep the first file only', function () {
    $source = (new FakeContentSource)->addFile('Readme.md', 'one')->addFile('readme.md', 'two');
    $workspace = temporaryWorkspace();

    $result = (new GitHubTreeFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits());

    expect($result->files)->toBe(['Readme.md'])
        ->and($result->skipped[0]->reason)->toBe(SkipReason::NameCollision);
});
