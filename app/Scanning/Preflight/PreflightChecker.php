<?php

declare(strict_types=1);

namespace App\Scanning\Preflight;

use App\Scanning\Contracts\Analyser;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\PreflightConfig;
use App\Scanning\Data\PreflightResult;
use App\Scanning\Data\SkippedFile;
use App\Scanning\Detect\DependencyIndex;
use App\Scanning\Enums\Severity;
use App\Scanning\Enums\SkipReason;
use App\Scanning\Exceptions\PreflightFailedException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Support\FileWalker;
use App\Scanning\Support\PathGuard;
use App\Scanning\Support\PathMatcher;

/**
 * Validates the fetched files before any analyser sees them. Hard rules
 * (symlinks, path escapes, size limits) fail the scan; soft rules (binaries,
 * generated files, dependency directories) delete the file from the
 * workspace and record why. Security scanners run last and their hits are
 * reported as critical findings.
 */
final class PreflightChecker
{
    /**
     * @param  list<Analyser>  $securityAnalysers  Run on every repository regardless of stack.
     */
    public function __construct(private readonly array $securityAnalysers = []) {}

    public function check(ScanWorkspace $workspace, PreflightConfig $config): PreflightResult
    {
        $repoPath = $workspace->repoPath();
        $entries = FileWalker::walk($repoPath);

        foreach ($entries as $entry) {
            if ($entry['is_link']) {
                throw new PreflightFailedException("The fetched files contain a symbolic link ({$entry['path']}). Symlinks are never scanned.");
            }

            if (! PathGuard::isInside($repoPath, $entry['absolute'])) {
                throw new PreflightFailedException("A fetched file resolves outside the scan directory ({$entry['path']}).");
            }
        }

        if (count($entries) > $config->maxFileCount) {
            throw new PreflightFailedException("The repository has more than {$config->maxFileCount} files to scan.");
        }

        $totalBytes = array_sum(array_column($entries, 'size'));
        if ($totalBytes > $config->maxTotalBytes) {
            throw new PreflightFailedException('The repository exceeds the total size limit.');
        }

        $files = [];
        $skipped = [];
        $keptBytes = 0;

        foreach ($entries as $entry) {
            $reason = $this->skipReasonFor($entry, $config);

            if ($reason === null) {
                $files[] = $entry['path'];
                $keptBytes += $entry['size'];

                continue;
            }

            $skipped[] = new SkippedFile($entry['path'], $reason[0], $reason[1]);
            @unlink($entry['absolute']);
        }

        $findings = new FindingCollection;
        foreach ($this->securityAnalysers as $analyser) {
            $findings = $findings->merge(
                $analyser->run($repoPath)->map(fn (Finding $f) => $f->with(['severity' => Severity::Critical->value]))
            );
        }

        return new PreflightResult($files, $skipped, $findings, $keptBytes);
    }

    /**
     * @param  array{path: string, absolute: string, is_link: bool, size: int}  $entry
     * @return array{0: SkipReason, 1: string|null}|null
     */
    private function skipReasonFor(array $entry, PreflightConfig $config): ?array
    {
        $limit = DependencyIndex::isLockfile($entry['path']) ? max($config->maxSingleFileBytes, $config->maxLockfileBytes) : $config->maxSingleFileBytes;
        if ($entry['size'] > $limit) {
            return [SkipReason::Oversized, "{$entry['size']} bytes"];
        }

        $directory = PathMatcher::skippedDirectoryFor($entry['path'], $config->skippedDirectories);
        if ($directory !== null) {
            return [SkipReason::DependencyDirectory, $directory.'/'];
        }

        $binary = BinaryDetector::detect($entry['absolute']);
        if ($binary !== null) {
            return [$binary, null];
        }

        // Lockfiles are generated (composer.lock even says "@generated" in its header) but they are the
        // only exact record of what is installed, so they are kept and read as data, never as code.
        if (DependencyIndex::isLockfile($entry['path'])) {
            return null;
        }

        $generated = GeneratedFileDetector::detect($entry['path'], $entry['absolute'], $entry['size'], $config->generatedFilePatterns);
        if ($generated !== null) {
            return [$generated, null];
        }

        return null;
    }
}
