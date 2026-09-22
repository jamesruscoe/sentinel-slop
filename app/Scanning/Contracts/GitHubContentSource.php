<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

/**
 * Read-only access to a repository's tree and blobs. Implemented against the
 * GitHub REST API in production and by an in-memory fake in tests.
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
     * The recursive tree for a commit or tree SHA.
     *
     * @return array{sha: string, truncated: bool, tree: list<array{path: string, mode: string, type: string, sha: string, size?: int}>}
     */
    public function getTree(string $owner, string $repo, string $sha): array;

    /** Raw blob bytes. */
    public function getBlob(string $owner, string $repo, string $sha): string;

    /**
     * Language name => bytes, as reported by GitHub.
     *
     * @return array<string, int>
     */
    public function getLanguages(string $owner, string $repo): array;
}
