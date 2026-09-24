<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

/**
 * Read-only access to a repository: its default branch, a branch head and a
 * gzipped tarball of a commit. Implemented against the GitHub REST API in
 * production, a local directory in development and an in-memory fake in tests.
 */
interface GitHubContentSource
{
    /**
     * @return array{default_branch: string}
     */
    public function getRepository(string $owner, string $repo): array;

    /** The commit SHA at the head of a branch. */
    public function getBranchHead(string $owner, string $repo, string $branch): string;

    /**
     * Write the gzipped tarball of a commit to $destination, streaming, and throw
     * FetchLimitExceededException as soon as more than $maxBytes have arrived.
     * One request whatever the file count, unlike a blob per file.
     */
    public function downloadArchive(string $owner, string $repo, string $ref, string $destination, int $maxBytes): void;

    /**
     * Language name => bytes, as reported by GitHub.
     *
     * @return array<string, int>
     */
    public function getLanguages(string $owner, string $repo): array;
}
