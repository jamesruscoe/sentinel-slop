<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Contracts\RepositoryFetcher;
use App\Scanning\Data\FetchLimits;
use App\Scanning\Data\FetchResult;
use App\Scanning\Data\RepositoryRef;
use App\Scanning\Data\SkippedFile;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Exceptions\UnsafePathException;
use App\Scanning\Support\PathGuard;
use App\Scanning\Support\PathMatcher;

/**
 * Fetches a repository through the Git Trees API, one regular blob at a
 * time. No git clone, no symlinks, no submodules, no dependency directories.
 * Limits are enforced before and during download so an oversized repository
 * aborts early instead of filling the disk.
 */
final class GitHubTreeFetcher implements RepositoryFetcher
{
    private const REGULAR_FILE_MODES = ['100644', '100755'];

    public function __construct(private readonly GitHubContentSource $source) {}

    public function fetch(RepositoryRef $repository, ScanWorkspace $workspace, FetchLimits $limits, ?callable $onProgress = null): FetchResult
    {
        $owner = $repository->owner;
        $name = $repository->name;
        $branch = $repository->branch ?? $this->source->getRepository($owner, $name)['default_branch'];
        $commitSha = $this->source->getBranchHead($owner, $name, $branch);
        $tree = $this->source->getTree($owner, $name, $commitSha);

        if ($tree['truncated']) {
            throw new FetchLimitExceededException('The repository has too many files for GitHub to list in one tree. Scans are limited to repositories GitHub can list in full.');
        }

        [$blobs, $skipped, $treePaths] = $this->plan($tree['tree'], $limits);

        $repoPath = $workspace->repoPath();
        $files = [];
        $written = 0;
        $done = 0;
        $total = count($blobs);
        $seenLower = [];

        foreach ($blobs as [$path, $sha, $expectedSize]) {
            $lower = strtolower($path);
            if (isset($seenLower[$lower])) {
                $skipped[] = new SkippedFile($path, SkipReason::NameCollision, "Collides with {$seenLower[$lower]}");
                $done++;

                continue;
            }
            $seenLower[$lower] = $path;

            $content = $this->source->getBlob($owner, $name, $sha);
            $size = strlen($content);

            if ($size > $limits->limitFor($path)) {
                $skipped[] = new SkippedFile($path, SkipReason::Oversized, "{$size} bytes");
                $done++;

                continue;
            }

            $written += $size;
            if ($written > $limits->maxTotalBytes) {
                throw new FetchLimitExceededException(sprintf('The repository exceeds the %s total size limit.', self::humanBytes($limits->maxTotalBytes)));
            }

            $this->write($repoPath, $path, $content);
            $files[] = $path;
            $done++;

            if ($onProgress !== null && ($done % 25 === 0 || $done === $total)) {
                $onProgress($done, $total);
            }
        }

        return new FetchResult($commitSha, $files, $treePaths, $written, $skipped);
    }

    /**
     * Decide what to download before touching the network for blobs, using
     * the sizes GitHub reports in the tree. Throws as soon as a limit is hit.
     *
     * @param  list<array{path: string, mode: string, type: string, sha: string, size?: int}>  $entries
     * @return array{0: list<array{0: string, 1: string, 2: int}>, 1: list<SkippedFile>, 2: list<string>}
     */
    private function plan(array $entries, FetchLimits $limits): array
    {
        $blobs = [];
        $skipped = [];
        $treePaths = [];
        $skippedDirectoryCounts = [];
        $notAnalysedCounts = [];
        $count = 0;
        $bytes = 0;

        foreach ($entries as $entry) {
            $path = $entry['path'];
            $treePaths[] = $path;

            if ($entry['type'] === 'commit' || $entry['mode'] === '160000') {
                $skipped[] = new SkippedFile($path, SkipReason::Submodule);

                continue;
            }

            if ($entry['mode'] === '120000') {
                $skipped[] = new SkippedFile($path, SkipReason::Symlink);

                continue;
            }

            if ($entry['type'] !== 'blob') {
                continue;
            }

            if (! in_array($entry['mode'], self::REGULAR_FILE_MODES, true)) {
                $skipped[] = new SkippedFile($path, SkipReason::UnsupportedMode, "mode {$entry['mode']}");

                continue;
            }

            $safePath = PathGuard::assertSafeRelativePath($path);

            $directory = PathMatcher::skippedDirectoryFor($safePath, $limits->skippedDirectories);
            if ($directory !== null) {
                $skippedDirectoryCounts[$directory] = ($skippedDirectoryCounts[$directory] ?? 0) + 1;

                continue;
            }

            // Files no analyser reads and files preflight would delete are decided from the path here, so they are
            // never downloaded and never counted: the limits judge what would be analysed (Django's 2,537 locale
            // catalogues and Filament's 112 MB of images pushed both past limits that never applied to their code).
            if ($limits->isNotAnalysed($safePath)) {
                $extension = '*.'.strtolower(pathinfo($safePath, PATHINFO_EXTENSION));
                $notAnalysedCounts[$extension] = ($notAnalysedCounts[$extension] ?? 0) + 1;

                continue;
            }
            if ($limits->isGenerated($safePath)) {
                $minified = preg_match('/\.min\.(js|css)$/i', $safePath) === 1;
                $skipped[] = new SkippedFile($safePath, $minified ? SkipReason::Minified : SkipReason::Generated, 'not downloaded');

                continue;
            }

            $size = (int) ($entry['size'] ?? 0);
            if ($size > $limits->limitFor($safePath)) {
                $skipped[] = new SkippedFile($safePath, SkipReason::Oversized, "{$size} bytes");

                continue;
            }

            if (++$count > $limits->maxFileCount) {
                throw new FetchLimitExceededException("The repository has more than {$limits->maxFileCount} files to scan.");
            }

            $bytes += $size;
            if ($bytes > $limits->maxTotalBytes) {
                throw new FetchLimitExceededException(sprintf('The repository exceeds the %s total size limit.', self::humanBytes($limits->maxTotalBytes)));
            }

            $blobs[] = [$safePath, $entry['sha'], $size];
        }

        foreach ($skippedDirectoryCounts as $directory => $fileCount) {
            $skipped[] = new SkippedFile($directory.'/', SkipReason::DependencyDirectory, "{$fileCount} files not downloaded");
        }
        foreach ($notAnalysedCounts as $extension => $fileCount) {
            $skipped[] = new SkippedFile($extension, SkipReason::NotAnalysed, "{$fileCount} files not downloaded");
        }

        return [$blobs, $skipped, $treePaths];
    }

    private function write(string $repoPath, string $relativePath, string $content): void
    {
        $absolute = $repoPath.'/'.$relativePath;
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new UnsafePathException("Could not create directory for {$relativePath}.");
        }

        if (! PathGuard::isInside($repoPath, $directory)) {
            throw new UnsafePathException("Refusing to write outside the scan directory: {$relativePath}");
        }

        file_put_contents($absolute, $content);

        if (! PathGuard::isInside($repoPath, $absolute)) {
            @unlink($absolute);
            throw new UnsafePathException("Refusing to keep a file outside the scan directory: {$relativePath}");
        }
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024).' MB' : round($bytes / 1024).' KB';
    }
}
