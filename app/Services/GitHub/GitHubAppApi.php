<?php

namespace App\Services\GitHub;

/**
 * The slice of the GitHub API the app needs, behind an interface so tests
 * can substitute a fake and so the HTTP client library stays in one place.
 */
interface GitHubAppApi
{
    /**
     * GET /app/installations/{id}, authenticated as the app.
     *
     * @return array<string, mixed>
     */
    public function getInstallation(int $installationId): array;

    /**
     * POST /app/installations/{id}/access_tokens, authenticated as the app.
     */
    public function createInstallationToken(int $installationId): InstallationToken;

    /**
     * GET /installation/repositories (all pages), authenticated as the installation.
     *
     * @return list<array<string, mixed>>
     */
    public function listInstallationRepositories(InstallationToken $token): array;
}
