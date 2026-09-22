<?php

namespace Tests\Support;

use App\Services\GitHub\GitHubAppApi;
use App\Services\GitHub\InstallationToken;
use Carbon\CarbonImmutable;
use RuntimeException;

final class FakeGitHubAppApi implements GitHubAppApi
{
    /** @var array<int, array<string, mixed>> */
    public array $installations = [];

    /** @var list<array<string, mixed>> */
    public array $repositories = [];

    public int $tokensMinted = 0;

    public function getInstallation(int $installationId): array
    {
        return $this->installations[$installationId]
            ?? throw new RuntimeException("Installation {$installationId} not found");
    }

    public function createInstallationToken(int $installationId): InstallationToken
    {
        $this->tokensMinted++;

        return new InstallationToken('ghs_fake_'.$installationId, CarbonImmutable::now()->addHour());
    }

    public function listInstallationRepositories(InstallationToken $token): array
    {
        return $this->repositories;
    }
}
