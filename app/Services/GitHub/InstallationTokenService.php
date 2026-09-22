<?php

namespace App\Services\GitHub;

use App\Models\Installation;

/**
 * Mints short-lived installation tokens. Tokens are returned to the caller
 * and never stored, cached or logged.
 */
final class InstallationTokenService
{
    public function __construct(private readonly GitHubAppApi $api) {}

    public function mint(Installation $installation): InstallationToken
    {
        return $this->mintFor($installation->github_installation_id);
    }

    public function mintFor(int $githubInstallationId): InstallationToken
    {
        return $this->api->createInstallationToken($githubInstallationId);
    }
}
