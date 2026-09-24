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
 * Fetches a repository as one gzipped tarball (three API requests per scan:
 * branch head, archive, languages) and extracts it entry by entry under the
 * same rules the blob-per-file fetcher applied: dependency directories,
 * non-analysed extensions and generated files are never written; symlinks,
 * hard links and anything that is not a regular file are recorded and never
 * written; every path goes through PathGuard; the file-count and byte limits
 * are enforced while extracting, so an oversized repository aborts early.
 * No git clone, nothing executed.
 */
final class TarballFetcher implements RepositoryFetcher
{
    private const PROGRESS_EVERY = 100;

    private const ARCHIVE_NAME = 'archive.tar.gz';

    public function __construct(private readonly GitHubContentSource $source) {}

    /**
     * @param  callable(int): void|null  $onProgress  called with the number of files extracted so far
     */
    public function fetch(RepositoryRef $repository, ScanWorkspace $workspace, FetchLimits $limits, ?callable $onProgress = null): FetchResult
    {
        $owner = $repository->owner;
        $name = $repository->name;
        $branch = $repository->branch ?? $this->source->getRepository($owner, $name)['default_branch'];
        $commitSha = $this->source->getBranchHead($owner, $name, $branch);

        $archive = $workspace->workPath().'/'.self::ARCHIVE_NAME;
        // The compressed archive may not exceed the byte limit either: it cannot be smaller than what it holds.
        $this->source->downloadArchive($owner, $name, $commitSha, $archive, $limits->maxTotalBytes);

        try {
            return $this->extract($archive, $commitSha, $workspace->repoPath(), $limits, $onProgress);
        } finally {
            @unlink($archive);
        }
    }

    /**
     * @param  callable(int): void|null  $onProgress
     */
    private function extract(string $archive, string $commitSha, string $repoPath, FetchLimits $limits, ?callable $onProgress): FetchResult
    {
        $reader = TarStreamReader::openGzip($archive);
        $files = [];
        $treePaths = [];
        $skipped = [];
        $skippedDirectoryCounts = [];
        $notAnalysedCounts = [];
        $seenLower = [];
        $written = 0;
        $count = 0;

        foreach ($reader->entries() as $entry) {
            $path = self::stripRoot($entry->name);
            if ($path === null) {
                continue;
            }
            $treePaths[] = $path;

            if ($entry->isDirectory()) {
                continue;
            }
            if ($entry->isSymlink()) {
                $skipped[] = new SkippedFile($path, SkipReason::Symlink);

                continue;
            }
            if (! $entry->isRegular()) {
                $skipped[] = new SkippedFile($path, SkipReason::UnsupportedMode, $entry->isHardLink() ? 'hard link' : "tar type {$entry->type}");

                continue;
            }

            $safePath = PathGuard::assertSafeRelativePath($path);

            $directory = PathMatcher::skippedDirectoryFor($safePath, $limits->skippedDirectories);
            if ($directory !== null) {
                $skippedDirectoryCounts[$directory] = ($skippedDirectoryCounts[$directory] ?? 0) + 1;

                continue;
            }
            if ($limits->isNotAnalysed($safePath)) {
                $extension = '*.'.strtolower(pathinfo($safePath, PATHINFO_EXTENSION));
                $notAnalysedCounts[$extension] = ($notAnalysedCounts[$extension] ?? 0) + 1;

                continue;
            }
            if ($limits->isGenerated($safePath)) {
                $minified = preg_match('/\.min\.(js|css)$/i', $safePath) === 1;
                $skipped[] = new SkippedFile($safePath, $minified ? SkipReason::Minified : SkipReason::Generated, 'not extracted');

                continue;
            }
            if ($entry->size > $limits->limitFor($safePath)) {
                $skipped[] = new SkippedFile($safePath, SkipReason::Oversized, "{$entry->size} bytes");

                continue;
            }

            $lower = strtolower($safePath);
            if (isset($seenLower[$lower])) {
                $skipped[] = new SkippedFile($safePath, SkipReason::NameCollision, "Collides with {$seenLower[$lower]}");

                continue;
            }
            $seenLower[$lower] = $safePath;

            if (++$count > $limits->maxFileCount) {
                throw new FetchLimitExceededException("The repository has more than {$limits->maxFileCount} files to scan.");
            }
            if ($written + $entry->size > $limits->maxTotalBytes) {
                throw new FetchLimitExceededException(sprintf('The repository exceeds the %s total size limit.', self::humanBytes($limits->maxTotalBytes)));
            }

            $written += $this->write($repoPath, $safePath, $entry, $limits->maxTotalBytes - $written);
            $files[] = $safePath;

            if ($onProgress !== null && $count % self::PROGRESS_EVERY === 0) {
                $onProgress($count);
            }
        }

        if ($onProgress !== null && $count % self::PROGRESS_EVERY !== 0) {
            $onProgress($count);
        }

        foreach ($skippedDirectoryCounts as $directory => $fileCount) {
            $skipped[] = new SkippedFile($directory.'/', SkipReason::DependencyDirectory, "{$fileCount} files not extracted");
        }
        foreach ($notAnalysedCounts as $extension => $fileCount) {
            $skipped[] = new SkippedFile($extension, SkipReason::NotAnalysed, "{$fileCount} files not extracted");
        }

        // `git archive` records the commit in a pax global header; the API head is used when it does not.
        $archiveCommit = $reader->globalHeaders()['comment'] ?? '';

        return new FetchResult(preg_match('/^[0-9a-f]{40}$/', $archiveCommit) === 1 ? $archiveCommit : $commitSha, $files, $treePaths, $written, $skipped);
    }

    /**
     * Archive paths start with one root folder (`owner-repo-sha/`); the root
     * itself and anything outside it are not files of the repository.
     */
    private static function stripRoot(string $name): ?string
    {
        $slash = strpos($name, '/');
        if ($slash === false) {
            return null;
        }
        $path = rtrim(substr($name, $slash + 1), '/');

        return $path === '' ? null : $path;
    }

    /**
     * Streams the entry to disk in chunks; the byte budget is enforced on what
     * actually arrives, not only on the size the header declares.
     */
    private function write(string $repoPath, string $relativePath, TarEntry $entry, int $budget): int
    {
        $absolute = $repoPath.'/'.$relativePath;
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new UnsafePathException("Could not create directory for {$relativePath}.");
        }
        if (! PathGuard::isInside($repoPath, $directory)) {
            throw new UnsafePathException("Refusing to write outside the scan directory: {$relativePath}");
        }

        $handle = fopen($absolute, 'wb');
        if ($handle === false) {
            throw new UnsafePathException("Could not write {$relativePath}.");
        }

        $bytes = 0;
        try {
            $entry->copyTo(function (string $chunk) use ($handle, &$bytes, $budget): void {
                $bytes += strlen($chunk);
                if ($bytes > $budget) {
                    throw new FetchLimitExceededException('The repository exceeds the total size limit.');
                }
                fwrite($handle, $chunk);
            });
        } finally {
            fclose($handle);
        }

        if (! PathGuard::isInside($repoPath, $absolute)) {
            @unlink($absolute);
            throw new UnsafePathException("Refusing to keep a file outside the scan directory: {$relativePath}");
        }

        return $bytes;
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024).' MB' : round($bytes / 1024).' KB';
    }
}
