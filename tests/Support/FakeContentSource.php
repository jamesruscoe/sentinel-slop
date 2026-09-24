<?php

namespace Tests\Support;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Fetch\TarWriter;
use App\Scanning\Support\FileWalker;

/**
 * An in-memory repository served as a gzipped tarball, exactly what GitHub's
 * tarball endpoint returns. Tests can add entries a fixture directory could
 * never hold: symlinks, hard links, unsafe paths, paths over 100 bytes.
 */
final class FakeContentSource implements GitHubContentSource
{
    /** @var list<array{path: string, kind: string, content: string, target: string}> */
    public array $entries = [];

    /** @var array<string, int> */
    public array $languages = ['PHP' => 1000];

    public string $defaultBranch = 'main';

    public string $headSha = 'abc123def4567890abc123def4567890abc123de';

    /** The commit `git archive` records in the pax global header; null leaves it out. */
    public ?string $archiveComment = null;

    public int $archiveRequests = 0;

    public ?\Throwable $failWith = null;

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

    /**
     * @param  int|null  $size  pad the content to this many bytes (a big image, an oversized file)
     */
    public function addFile(string $path, string $content, ?int $size = null): self
    {
        if ($size !== null && strlen($content) < $size) {
            $content = str_pad($content, $size, 'x');
        }
        $this->entries[] = ['path' => $path, 'kind' => 'file', 'content' => $content, 'target' => ''];

        return $this;
    }

    public function addSymlink(string $path, string $target): self
    {
        $this->entries[] = ['path' => $path, 'kind' => 'symlink', 'content' => '', 'target' => $target];

        return $this;
    }

    public function addHardLink(string $path, string $target): self
    {
        $this->entries[] = ['path' => $path, 'kind' => 'hardlink', 'content' => '', 'target' => $target];

        return $this;
    }

    /** `git archive` writes a submodule as an empty directory. */
    public function addSubmodule(string $path): self
    {
        return $this->addTree($path);
    }

    public function addTree(string $path): self
    {
        $this->entries[] = ['path' => $path, 'kind' => 'tree', 'content' => '', 'target' => ''];

        return $this;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_column($this->entries, 'path');
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

    public function downloadArchive(string $owner, string $repo, string $ref, string $destination, int $maxBytes): void
    {
        $this->archiveRequests++;
        $this->log[] = "archive {$ref}";

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $writer = TarWriter::createGzip($destination);
        if ($this->archiveComment !== null) {
            $writer->globalHeader(['comment' => $this->archiveComment]);
        }
        $root = "{$owner}-{$repo}-".substr($ref, 0, 7).'/';

        foreach ($this->entries as $entry) {
            match ($entry['kind']) {
                'file' => $writer->file($root.$entry['path'], $entry['content']),
                'symlink' => $writer->symlink($root.$entry['path'], $entry['target']),
                'hardlink' => $writer->hardLink($root.$entry['path'], $root.$entry['target']),
                'tree' => $writer->directory($root.$entry['path']),
            };
        }

        $writer->close();
    }

    public function getLanguages(string $owner, string $repo): array
    {
        return $this->languages;
    }
}
