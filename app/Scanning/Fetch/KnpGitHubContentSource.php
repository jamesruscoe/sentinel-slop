<?php

declare(strict_types=1);

namespace App\Scanning\Fetch;

use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Exceptions\FetchLimitExceededException;
use App\Scanning\Exceptions\RepositoryUnavailableException;
use Github\AuthMethod;
use Github\Client;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use SensitiveParameter;

final class KnpGitHubContentSource implements GitHubContentSource
{
    public function __construct(private readonly Client $client, #[SensitiveParameter] private readonly ?string $token = null) {}

    public static function withToken(#[SensitiveParameter] string $token): self
    {
        $client = new Client;
        $client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);

        return new self($client, $token);
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

    public function downloadArchive(string $owner, string $repo, string $ref, string $destination, int $maxBytes): void
    {
        $path = sprintf('/repos/%s/%s/tarball/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($ref));

        if ($this->token !== null) {
            // A plain Guzzle request with `stream => true`: the Knp client's plugin stack buffers the whole body
            // before handing it over, so the byte cap could not stop a huge archive early. GitHub answers with a
            // redirect to codeload.github.com; Guzzle follows it and drops the Authorization header on the host change.
            try {
                $response = (new GuzzleClient(['base_uri' => 'https://api.github.com', 'timeout' => 300, 'connect_timeout' => 15]))->get($path, [
                    'stream' => true,
                    'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->token,
                        'Accept' => 'application/vnd.github+json',
                        'User-Agent' => 'sentinel-slop',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ],
                ]);
            } catch (ClientException $e) {
                // Never chain the Guzzle exception: its request carries the token in a header.
                $status = $e->getResponse()->getStatusCode();
                $rateLimited = in_array($status, [403, 429], true) && ($e->getResponse()->getHeaderLine('x-ratelimit-remaining') === '0' || stripos((string) $e->getResponse()->getBody(), 'rate limit') !== false);

                throw new RepositoryUnavailableException($rateLimited
                    ? "GitHub's API rate limit for this installation was reached while fetching {$owner}/{$repo}. Try again in an hour."
                    : "GitHub could not serve {$owner}/{$repo} (HTTP {$status}). Check that the app is still installed on this repository.", $status);
            } catch (GuzzleException) {
                throw new RepositoryUnavailableException("GitHub could not serve the archive of {$owner}/{$repo}. Please try again later.");
            }
        } else {
            $response = $this->guard(fn () => $this->client->getHttpClient()->get($path), $owner, $repo);
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new RepositoryUnavailableException("Could not write the archive for {$owner}/{$repo}.");
        }

        $total = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '') {
                    break;
                }
                $total += strlen($chunk);
                if ($total > $maxBytes) {
                    throw new FetchLimitExceededException(sprintf('The repository archive exceeds the %d MB limit before extraction.', intdiv($maxBytes, 1024 * 1024)));
                }
                fwrite($out, $chunk);
            }
        } catch (FetchLimitExceededException $e) {
            fclose($out);
            @unlink($destination);
            throw $e;
        }
        fclose($out);
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
            // Every blob is one request: a large repository can exhaust the installation's hourly API allowance
            // mid-fetch, and that must not read as "the app is not installed".
            $rateLimited = in_array($e->getCode(), [403, 429], true) && stripos($e->getMessage(), 'rate limit') !== false;

            throw new RepositoryUnavailableException(
                $rateLimited
                    ? "GitHub's API rate limit for this installation was reached while fetching {$owner}/{$repo}. Try again in an hour."
                    : "GitHub could not serve {$owner}/{$repo} (HTTP {$e->getCode()}). Check that the app is still installed on this repository.",
                $e->getCode(),
                $e,
            );
        }
    }
}
