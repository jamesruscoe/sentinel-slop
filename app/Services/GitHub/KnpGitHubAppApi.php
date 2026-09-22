<?php

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use Github\AuthMethod;
use Github\Client;
use Github\ResultPager;
use RuntimeException;

final class KnpGitHubAppApi implements GitHubAppApi
{
    public function __construct(private readonly GitHubAppJwt $jwt) {}

    public function getInstallation(int $installationId): array
    {
        /** @var array<string, mixed> $installation */
        $installation = $this->asApp()->apps()->getInstallation($installationId);

        return $installation;
    }

    public function createInstallationToken(int $installationId): InstallationToken
    {
        /** @var array{token?: string, expires_at?: string} $result */
        $result = $this->asApp()->apps()->createInstallationToken($installationId);

        if (! isset($result['token'], $result['expires_at'])) {
            throw new RuntimeException('GitHub did not return an installation token.');
        }

        return new InstallationToken($result['token'], CarbonImmutable::parse($result['expires_at']));
    }

    public function listInstallationRepositories(InstallationToken $token): array
    {
        $client = $this->asInstallation($token);
        $pager = new ResultPager($client, 100);

        /** @var array{repositories?: list<array<string, mixed>>} $page */
        $page = $pager->fetch($client->apps(), 'listRepositories');
        $repositories = $page['repositories'] ?? [];

        while ($pager->hasNext()) {
            /** @var array{repositories?: list<array<string, mixed>>} $page */
            $page = $pager->fetchNext();
            $repositories = [...$repositories, ...($page['repositories'] ?? [])];
        }

        return $repositories;
    }

    private function asApp(): Client
    {
        $client = new Client;
        $client->authenticate($this->jwt->create(), null, AuthMethod::JWT);

        return $client;
    }

    private function asInstallation(InstallationToken $token): Client
    {
        $client = new Client;
        $client->authenticate($token->value(), null, AuthMethod::ACCESS_TOKEN);

        return $client;
    }
}
