<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The per-scan temp directory: `repo/` holds fetched files, `work/` holds
 * JSON artifacts passed between pipeline stages and raw tool output.
 */
final class ScanWorkspace
{
    private function __construct(public readonly string $root) {}

    public static function at(string $root): self
    {
        return new self(rtrim(str_replace(chr(92), '/', $root), '/'));
    }

    public static function create(string $basePath, string $id): self
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1) {
            throw new InvalidArgumentException('Workspace ids may only contain letters, digits and dashes.');
        }

        $workspace = self::at(rtrim(str_replace(chr(92), '/', $basePath), '/').'/'.$id);

        foreach ([$workspace->repoPath(), $workspace->workPath()] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create workspace directory {$directory}");
            }
        }

        return $workspace;
    }

    public function repoPath(): string
    {
        return $this->root.'/repo';
    }

    public function workPath(): string
    {
        return $this->root.'/work';
    }

    public function exists(): bool
    {
        return is_dir($this->root);
    }

    public function delete(): void
    {
        if (! $this->exists()) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if (is_link($path) || ! is_dir($path)) {
                @chmod($path, 0666);
                @unlink($path) || @rmdir($path);

                continue;
            }

            @rmdir($path);
        }

        @rmdir($this->root);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeArtifact(string $name, array $data): void
    {
        $this->assertArtifactName($name);
        file_put_contents($this->workPath().'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readArtifact(string $name): ?array
    {
        $this->assertArtifactName($name);
        $file = $this->workPath().'/'.$name.'.json';

        if (! is_file($file)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string> Artifact names under a work sub-directory, e.g. "findings/phpstan".
     */
    public function listArtifacts(string $directory): array
    {
        $this->assertArtifactName($directory);
        $files = glob($this->workPath().'/'.$directory.'/*.json') ?: [];

        return array_map(fn (string $f) => $directory.'/'.basename($f, '.json'), $files);
    }

    private function assertArtifactName(string $name): void
    {
        if (preg_match('~^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$~', $name) !== 1) {
            throw new InvalidArgumentException("Invalid artifact name: {$name}");
        }

        $directory = dirname($this->workPath().'/'.$name);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }
}
