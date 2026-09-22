<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Exceptions\RepositoryUnavailableException;
use Github\AuthMethod;
use Github\Client;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use SensitiveParameter;

final class KnpGitHubContentSource implements GitHubContentSource
{
    public function __construct(private readonly Client $client) {}

    public static function withToken(#[SensitiveParameter] string $token): self
    {
        $client = new Client;
        $client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);

        return new self($client);
    }

    public function getRepository(string $owner, string $repo): array
    {
        /** @var array{default_branch?: string} $data */
        $data = $this->guard(fn () => $this->client->repo()->show($owner, $repo), $owner, $repo);

        return ['default_branch' => (string) ($data['default_branch'] ?? 'main')];
    }

    public function getBranchHead(string $owner, string $repo, string $branch): string
    {
        /** @var array{commit?: array{sha?: string}} $data */
        $data = $this->guard(fn () => $this->client->repo()->branches($owner, $repo, $branch), $owner, $repo);

        $sha = $data['commit']['sha'] ?? null;

        if (! is_string($sha) || $sha === '') {
            throw new RepositoryUnavailableException("GitHub did not return a commit for branch {$branch} of {$owner}/{$repo}.");
        }

        return $sha;
    }

    public function getTree(string $owner, string $repo, string $sha): array
    {
        /** @var array{sha?: string, truncated?: bool, tree?: list<array{path: string, mode: string, type: string, sha: string, size?: int}>} $data */
        $data = $this->guard(fn () => $this->client->gitData()->trees()->show($owner, $repo, $sha, true), $owner, $repo);

        return [
            'sha' => (string) ($data['sha'] ?? $sha),
            'truncated' => (bool) ($data['truncated'] ?? false),
            'tree' => $data['tree'] ?? [],
        ];
    }

    public function getBlob(string $owner, string $repo, string $sha): string
    {
        // Ask for the raw body so large blobs are not base64-wrapped in JSON.
        $response = $this->guard(fn () => $this->client->getHttpClient()->get(
            sprintf('/repos/%s/%s/git/blobs/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($sha)),
            ['Accept' => 'application/vnd.github.raw+json'],
        ), $owner, $repo);

        return (string) $response->getBody();
    }

    public function getLanguages(string $owner, string $repo): array
    {
        /** @var array<string, int> $data */
        $data = $this->guard(fn () => $this->client->repo()->languages($owner, $repo), $owner, $repo);

        return array_map('intval', $data);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(callable $call, string $owner, string $repo): mixed
    {
        try {
            return $call();
        } catch (GitHubRuntimeException $e) {
            throw new RepositoryUnavailableException(
                "GitHub could not serve {$owner}/{$repo} (HTTP {$e->getCode()}). Check that the app is still installed on this repository.",
                $e->getCode(),
                $e,
            );
        }
    }
}
