<?php

use App\Scanning\Data\FetchLimits;
use App\Scanning\Data\RepositoryRef;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Exceptions\UnsafePathException;
use App\Scanning\Fetch\TarballFetcher;
use App\Scanning\Fetch\TarStreamReader;
use App\Scanning\Fetch\TarWriter;
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

function fetchWith(FakeContentSource $source, ?FetchLimits $limits = null, ?callable $onProgress = null): array
{
    $workspace = temporaryWorkspace();
    $result = (new TarballFetcher($source))->fetch(new RepositoryRef('acme', 'repo', 'main'), $workspace, $limits ?? fetchLimits(), $onProgress);

    return [$result, $workspace];
}

test('it extracts regular files from the tarball into the workspace with one archive request', function () {
    $source = FakeContentSource::fromDirectory(fixturePath('laravel-basic'));
    $workspace = temporaryWorkspace();

    $result = (new TarballFetcher($source))->fetch(RepositoryRef::fromFullName('acme/laravel-basic'), $workspace, fetchLimits());

    expect($result->commitSha)->toBe($source->headSha)
        ->and($result->files)->toContain('app/Models/User.php', 'composer.json')
        ->and($result->treePaths)->toBe($source->paths())
        ->and(file_get_contents($workspace->repoPath().'/routes/web.php'))->toContain('HomeController')
        ->and($source->archiveRequests)->toBe(1)
        ->and($source->log)->toContain('repository acme/laravel-basic', 'head main', 'archive '.$source->headSha)
        ->and(file_exists($workspace->workPath().'/archive.tar.gz'))->toBeFalse();
});

test('the commit recorded by git archive wins over the branch head when present', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'a');
    $source->archiveComment = str_repeat('f', 40);

    [$result] = fetchWith($source);

    expect($result->commitSha)->toBe(str_repeat('f', 40));
});

test('it uses the given branch instead of asking for the default', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'a');

    (new TarballFetcher($source))->fetch(new RepositoryRef('acme', 'repo', 'develop'), temporaryWorkspace(), fetchLimits());

    expect($source->log)->toContain('head develop')->not->toContain('repository acme/repo');
});

test('symlinks, hard links and directories are recorded and never written', function () {
    $source = (new FakeContentSource)
        ->addFile('app.php', '<?php')
        ->addSymlink('link-to-etc', '/etc/passwd')
        ->addHardLink('hard', 'app.php')
        ->addSubmodule('lib/dep')
        ->addTree('app');

    [$result, $workspace] = fetchWith($source);

    expect($result->files)->toBe(['app.php'])
        ->and(skippedReasons($result->skipped))->toBe(['link-to-etc' => SkipReason::Symlink, 'hard' => SkipReason::UnsupportedMode])
        ->and($result->treePaths)->toBe(['app.php', 'link-to-etc', 'hard', 'lib/dep', 'app'])
        ->and(file_exists($workspace->repoPath().'/link-to-etc'))->toBeFalse()
        ->and(is_link($workspace->repoPath().'/link-to-etc'))->toBeFalse()
        ->and(file_exists($workspace->repoPath().'/hard'))->toBeFalse()
        ->and(is_dir($workspace->repoPath().'/lib'))->toBeFalse();
});

test('dependency directories are skipped without writing a single file', function () {
    $source = (new FakeContentSource)
        ->addFile('src/index.js', 'ok')
        ->addFile('vendor/autoload.php', '<?php evil();')
        ->addFile('vendor/composer/ClassLoader.php', '<?php')
        ->addFile('packages/ui/node_modules/left-pad/index.js', 'module.exports = 1');

    [$result, $workspace] = fetchWith($source);

    expect($result->files)->toBe(['src/index.js'])
        ->and(is_dir($workspace->repoPath().'/vendor'))->toBeFalse()
        ->and(skippedReasons($result->skipped))->toBe(['vendor/' => SkipReason::DependencyDirectory, 'packages/ui/node_modules/' => SkipReason::DependencyDirectory])
        ->and($result->skipped[0]->detail)->toBe('2 files not extracted');
});

test('images, catalogues and generated files are neither written nor counted toward the limits', function () {
    // Django: 2,537 .po/.mo files; Filament: 112 MB of images. Neither is analysed, so neither may trip a limit.
    $source = (new FakeContentSource)->addFile('app.py', 'print(1)')->addFile('locale/de/LC_MESSAGES/django.po', 'msgid ""')->addFile('locale/de/LC_MESSAGES/django.mo', 'x', size: 4_000_000)
        ->addFile('docs/logo.png', 'x', size: 9_000_000)->addFile('public/app.min.js', 'x', size: 3_000_000);

    [$result, $workspace] = fetchWith($source, fetchLimits(['maxFileCount' => 1, 'maxTotalBytes' => 100, 'skippedExtensions' => ['png', 'po', 'mo'], 'generatedFilePatterns' => ['*.min.js']]));

    expect($result->files)->toBe(['app.py'])
        ->and(array_map(fn ($s) => [$s->path, $s->reason->value, $s->detail], $result->skipped))->toBe([
            ['public/app.min.js', 'minified', 'not extracted'],
            ['*.po', 'not_analysed', '1 files not extracted'],
            ['*.mo', 'not_analysed', '1 files not extracted'],
            ['*.png', 'not_analysed', '1 files not extracted'],
        ])
        ->and(is_dir($workspace->repoPath().'/docs'))->toBeFalse();
});

test('a path escape attempt aborts extraction and nothing lands outside the workspace', function () {
    $source = (new FakeContentSource)->addFile('ok.php', '<?php')->addFile('../../escape.php', '<?php');
    $workspace = temporaryWorkspace();

    expect(fn () => (new TarballFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits()))
        ->toThrow(UnsafePathException::class);

    expect(file_exists(dirname($workspace->root).'/escape.php'))->toBeFalse()
        ->and(file_exists($workspace->root.'/escape.php'))->toBeFalse();
});

test('too many files aborts during extraction, before the extra files are written', function () {
    $source = new FakeContentSource;
    foreach (range(1, 8) as $i) {
        $source->addFile("f{$i}.txt", 'x');
    }
    $workspace = temporaryWorkspace();

    expect(fn () => (new TarballFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits(['maxFileCount' => 5])))
        ->toThrow(FetchLimitExceededException::class, 'more than 5 files');
    expect(file_exists($workspace->repoPath().'/f5.txt'))->toBeTrue()
        ->and(file_exists($workspace->repoPath().'/f6.txt'))->toBeFalse();
});

test('total size over the limit aborts during extraction', function () {
    $source = (new FakeContentSource)->addFile('a.txt', 'aaaa')->addFile('b.txt', 'bbbb')->addFile('c.txt', 'cccc');
    $workspace = temporaryWorkspace();

    expect(fn () => (new TarballFetcher($source))->fetch(new RepositoryRef('a', 'b', 'main'), $workspace, fetchLimits(['maxTotalBytes' => 9])))
        ->toThrow(FetchLimitExceededException::class, 'total size');
    expect(file_exists($workspace->repoPath().'/c.txt'))->toBeFalse();
});

test('an oversized file is skipped and its bytes discarded', function () {
    $source = (new FakeContentSource)->addFile('small.txt', 'ok')->addFile('huge.bin', 'x', size: 5_000_000)->addFile('after.txt', 'still here');

    [$result, $workspace] = fetchWith($source);

    expect($result->files)->toBe(['small.txt', 'after.txt'])
        ->and($result->skipped[0]->reason)->toBe(SkipReason::Oversized)
        ->and(file_exists($workspace->repoPath().'/huge.bin'))->toBeFalse()
        ->and(file_get_contents($workspace->repoPath().'/after.txt'))->toBe('still here');
});

test('case-insensitive filename collisions keep the first file only', function () {
    $source = (new FakeContentSource)->addFile('Readme.md', 'one')->addFile('readme.md', 'two');

    [$result] = fetchWith($source);

    expect($result->files)->toBe(['Readme.md'])
        ->and($result->skipped[0]->reason)->toBe(SkipReason::NameCollision);
});

test('paths over 100 bytes come through pax headers intact', function () {
    $deep = 'src/'.str_repeat('a-very-long-directory-name/', 5).'file-with-a-long-name.php';
    $source = (new FakeContentSource)->addFile($deep, '<?php // deep');

    [$result, $workspace] = fetchWith($source);

    expect(strlen($deep))->toBeGreaterThan(100)
        ->and($result->files)->toBe([$deep])
        ->and(file_get_contents($workspace->repoPath().'/'.$deep))->toBe('<?php // deep');
});

test('progress is reported every hundred files and at the end', function () {
    $source = new FakeContentSource;
    foreach (range(1, 250) as $i) {
        $source->addFile("f{$i}.txt", 'x');
    }
    $calls = [];

    fetchWith($source, null, function (int $extracted) use (&$calls) {
        $calls[] = $extracted;
    });

    expect($calls)->toBe([100, 200, 250]);
});

test('the tar reader streams entries and discards data the caller does not copy', function () {
    $path = sys_get_temp_dir().'/sentinel-tests/tar-'.bin2hex(random_bytes(4)).'.tar.gz';
    @mkdir(dirname($path), 0777, true);
    $writer = TarWriter::createGzip($path);
    $writer->globalHeader(['comment' => str_repeat('c', 40)]);
    $writer->file('root/big.bin', str_repeat('z', 3 * 1024 * 1024));
    $writer->symlink('root/link', '/etc/passwd');
    $writer->file('root/small.txt', 'hello');
    $writer->close();

    $reader = TarStreamReader::openGzip($path);
    $seen = [];
    foreach ($reader->entries() as $entry) {
        $chunks = [];
        if ($entry->name === 'root/small.txt') {
            $entry->copyTo(function (string $chunk) use (&$chunks) {
                $chunks[] = $chunk;
            });
        }
        $seen[] = [$entry->name, $entry->type, $entry->size, $entry->linkTarget, $chunks];
    }

    expect($seen)->toBe([
        ['root/big.bin', '0', 3 * 1024 * 1024, '', []],
        ['root/link', '2', 0, '/etc/passwd', []],
        ['root/small.txt', '0', 5, '', ['hello']],
    ])->and($reader->globalHeaders())->toBe(['comment' => str_repeat('c', 40)]);
    @unlink($path);
});
