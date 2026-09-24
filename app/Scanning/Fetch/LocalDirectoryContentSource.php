<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Contracts\GitHubContentSource;
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

    /** @var array<string, string>|null blob sha => absolute path, built on the first blob request */
    private ?array $blobs = null;

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

    public function getTree(string $owner, string $repo, string $sha): array
    {
        $tree = [];

        foreach (FileWalker::walk($this->directory) as $entry) {
            $tree[] = $entry['is_link']
                ? ['path' => $entry['path'], 'mode' => '120000', 'type' => 'blob', 'sha' => sha1('link|'.$entry['path']), 'size' => 0]
                : ['path' => $entry['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => sha1('file|'.$entry['path']), 'size' => $entry['size']];
        }

        return ['sha' => $sha, 'truncated' => false, 'tree' => $tree];
    }

    public function getBlob(string $owner, string $repo, string $sha): string
    {
        // Index the tree once: walking the directory per blob made fetching quadratic (3,400 files took ten minutes).
        if ($this->blobs === null) {
            $this->blobs = [];
            foreach (FileWalker::walk($this->directory) as $entry) {
                if (! $entry['is_link']) {
                    $this->blobs[sha1('file|'.$entry['path'])] = $entry['absolute'];
                }
            }
        }

        if (! isset($this->blobs[$sha])) {
            throw new RuntimeException("Unknown blob {$sha}");
        }

        return (string) file_get_contents($this->blobs[$sha]);
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
