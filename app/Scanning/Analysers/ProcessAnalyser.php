<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Contracts\Analyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\ProcessResult;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Exceptions\AnalyserFailedException;
use App\Scanning\Process\ToolLocator;

/**
 * Shared plumbing for analysers that shell out. The repo path must be the
 * `repo/` directory of a ScanWorkspace; tool scratch space and output files
 * go under the sibling `work/<tool>/` directory so they are deleted with
 * the scan and are never mistaken for repository files.
 */
abstract class ProcessAnalyser implements Analyser
{
    public function __construct(
        protected readonly ProcessRunner $runner,
        protected readonly ToolLocator $tools,
        protected readonly AnalyserOptions $options,
    ) {}

    protected function workDir(string $repoPath): string
    {
        $directory = dirname(rtrim(str_replace(chr(92), '/', $repoPath), '/')).'/work/'.$this->name();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AnalyserFailedException("Could not create work directory for {$this->name()}.");
        }

        return $directory;
    }

    protected function workspaceRoot(string $repoPath): string
    {
        return dirname(rtrim(str_replace(chr(92), '/', $repoPath), '/'));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function execute(array $command, string $cwd, array $env = []): ProcessResult
    {
        $result = $this->runner->run(new ProcessSpec($command, $cwd, $this->options->timeoutSeconds, $env));

        if ($result->timedOut) {
            throw new AnalyserFailedException("{$this->name()} exceeded the {$this->options->timeoutSeconds}s timeout.");
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(string $json, string $what): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new AnalyserFailedException("{$this->name()} produced unreadable {$what}: ".substr(trim($json), 0, 300));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readJsonFile(string $file, string $what): array
    {
        if (! is_file($file)) {
            throw new AnalyserFailedException("{$this->name()} did not write {$what} ({$file}).");
        }

        return $this->decodeJson((string) file_get_contents($file), $what);
    }

    protected function snippetFrom(string $repoPath, string $relativeFile, ?int $line, int $context = 1): ?string
    {
        if ($line === null || $line < 1) {
            return null;
        }

        $absolute = rtrim($repoPath, '/').'/'.ltrim(str_replace(chr(92), '/', $relativeFile), '/');
        if (! is_file($absolute)) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($absolute)) ?: [];
        $slice = array_slice($lines, max(0, $line - 1 - $context), 1 + 2 * $context);

        return trim(implode("\n", $slice)) === '' ? null : implode("\n", $slice);
    }
}
