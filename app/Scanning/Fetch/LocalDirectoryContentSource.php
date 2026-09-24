<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Support\FileWalker;
use RuntimeException;

/**
 * Serves a local directory as if it were a GitHub tree. Development and
 * testing only: lets the full pipeline run against a fixture without
 * GitHub. Symlinks are reported as mode 120000 so the fetcher skips them.
 */
final class LocalDirectoryContentSource implements GitHubContentSource
{
    /** @var array<string, int> */
    public array $languages = [];

    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Not a directory: {$directory}");
        }
    }

    public function getRepository(string $owner, string $repo): array
    {
        return ['default_branch' => 'main'];
    }

    public function getBranchHead(string $owner, string $repo, string $branch): string
    {
        return sha1('local|'.$this->directory.'|'.$branch);
    }

    /**
     * Packs the directory the way `git archive` would (one root folder, symlinks as
     * symlink entries) so the fetcher is exercised exactly as in production.
     */
    public function downloadArchive(string $owner, string $repo, string $ref, string $destination, int $maxBytes): void
    {
        $writer = TarWriter::createGzip($destination);
        $writer->globalHeader(['comment' => $ref]);
        $root = $repo.'-'.substr($ref, 0, 7).'/';

        foreach (FileWalker::walk($this->directory) as $entry) {
            if ($entry['is_link']) {
                $writer->symlink($root.$entry['path'], (string) (readlink($entry['absolute']) ?: 'target'));
            } else {
                $writer->file($root.$entry['path'], (string) file_get_contents($entry['absolute']));
            }
        }

        $writer->close();

        if (filesize($destination) > $maxBytes) {
            @unlink($destination);
            throw new FetchLimitExceededException(sprintf('The repository archive exceeds the %d MB limit before extraction.', intdiv($maxBytes, 1024 * 1024)));
        }
    }

    public function getLanguages(string $owner, string $repo): array
    {
        if ($this->languages !== []) {
            return $this->languages;
        }

        $bytes = [];
        $map = ['php' => 'PHP', 'js' => 'JavaScript', 'mjs' => 'JavaScript', 'cjs' => 'JavaScript', 'jsx' => 'JavaScript', 'ts' => 'TypeScript', 'tsx' => 'TypeScript', 'vue' => 'Vue', 'py' => 'Python', 'css' => 'CSS', 'html' => 'HTML'];

        foreach (FileWalker::walk($this->directory) as $entry) {
            if ($entry['is_link']) {
                continue;
            }
            $language = str_ends_with($entry['path'], '.blade.php') ? 'Blade' : ($map[strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION))] ?? null);
            if ($language !== null) {
                $bytes[$language] = ($bytes[$language] ?? 0) + $entry['size'];
            }
        }
        arsort($bytes);

        return $bytes;
    }
}
