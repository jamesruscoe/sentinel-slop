<?php

namespace Tests\Support;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Support\FileWalker;
use RuntimeException;

/**
 * An in-memory GitHub tree built from a fixture directory. Tests can add
 * synthetic entries (symlinks, submodules, unsafe paths) that could never be
 * checked into the fixture itself.
 */
final class FakeContentSource implements GitHubContentSource
{
    /** @var list<array{path: string, mode: string, type: string, sha: string, size?: int}> */
    public array $entries = [];

    /** @var array<string, string> sha => content */
    public array $blobs = [];

    /** @var array<string, int> */
    public array $languages = ['PHP' => 1000];

    public string $defaultBranch = 'main';

    public string $headSha = 'abc123def4567890abc123def4567890abc123de';

    public bool $truncated = false;

    public int $blobRequests = 0;

    /** @var list<string> */
    public array $log = [];

    public static function fromDirectory(string $directory): self
    {
        $source = new self;

        foreach (FileWalker::walk($directory) as $entry) {
            $source->addFile($entry['path'], (string) file_get_contents($entry['absolute']));
        }

        return $source;
    }

    public function addFile(string $path, string $content, string $mode = '100644', ?int $declaredSize = null): self
    {
        $sha = sha1($path.'|'.$content);
        $this->entries[] = ['path' => $path, 'mode' => $mode, 'type' => 'blob', 'sha' => $sha, 'size' => $declaredSize ?? strlen($content)];
        $this->blobs[$sha] = $content;

        return $this;
    }

    public function addSymlink(string $path, string $target): self
    {
        $this->entries[] = ['path' => $path, 'mode' => '120000', 'type' => 'blob', 'sha' => sha1('link|'.$path), 'size' => strlen($target)];
        $this->blobs[sha1('link|'.$path)] = $target;

        return $this;
    }

    public function addSubmodule(string $path): self
    {
        $this->entries[] = ['path' => $path, 'mode' => '160000', 'type' => 'commit', 'sha' => sha1('sub|'.$path)];

        return $this;
    }

    public function addTree(string $path): self
    {
        $this->entries[] = ['path' => $path, 'mode' => '040000', 'type' => 'tree', 'sha' => sha1('tree|'.$path)];

        return $this;
    }

    public function getRepository(string $owner, string $repo): array
    {
        $this->log[] = "repository {$owner}/{$repo}";

        return ['default_branch' => $this->defaultBranch];
    }

    public function getBranchHead(string $owner, string $repo, string $branch): string
    {
        $this->log[] = "head {$branch}";

        return $this->headSha;
    }

    public function getTree(string $owner, string $repo, string $sha): array
    {
        $this->log[] = "tree {$sha}";

        return ['sha' => $sha, 'truncated' => $this->truncated, 'tree' => $this->entries];
    }

    public function getBlob(string $owner, string $repo, string $sha): string
    {
        $this->blobRequests++;

        return $this->blobs[$sha] ?? throw new RuntimeException("Unknown blob {$sha}");
    }

    public function getLanguages(string $owner, string $repo): array
    {
        return $this->languages;
    }
}
