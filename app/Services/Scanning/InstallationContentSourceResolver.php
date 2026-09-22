<?php

namespace App\Services\Scanning;

use App\Models\Repository;
use App\Scanning\Contracts\GitHubContentSource;
use App\Scanning\Exceptions\RepositoryUnavailableException;
use App\Scanning\Fetch\KnpGitHubContentSource;
use App\Services\GitHub\InstallationTokenService;

final class InstallationContentSourceResolver implements ContentSourceResolver
{
    public function __construct(private readonly InstallationTokenService $tokens) {}

    public function forRepository(Repository $repository): GitHubContentSource
    {
        $installation = $repository->installation;

        if ($installation === null || ! $installation->isUsable()) {
            throw new RepositoryUnavailableException('The GitHub App installation for this repository is missing or suspended.');
        }

        // The token lives only in this call stack and inside the client; it is never stored.
        return KnpGitHubContentSource::withToken($this->tokens->mint($installation)->value());
    }
}
