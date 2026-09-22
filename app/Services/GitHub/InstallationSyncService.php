<?php

namespace App\Services\GitHub;

use App\Models\Installation;
use App\Models\Repository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Keeps installations and repositories in sync with GitHub. Webhooks are the
 * source of truth for ownership: the installation belongs to the user whose
 * GitHub id matches the webhook's `sender`.
 */
final class InstallationSyncService
{
    public function __construct(
        private readonly GitHubAppApi $api,
        private readonly InstallationTokenService $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInstallationEvent(array $payload): ?Installation
    {
        $githubId = (int) ($payload['installation']['id'] ?? 0);

        if ($githubId === 0) {
            return null;
        }

        return match ($payload['action'] ?? null) {
            'created' => $this->created($payload),
            'deleted' => $this->deleted($githubId),
            'suspend' => $this->setSuspended($githubId, true),
            'unsuspend' => $this->setSuspended($githubId, false),
            default => Installation::query()->where('github_installation_id', $githubId)->first(),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleRepositoriesEvent(array $payload): ?Installation
    {
        $githubId = (int) ($payload['installation']['id'] ?? 0);
        $installation = Installation::query()->where('github_installation_id', $githubId)->first();

        if ($installation === null) {
            return null;
        }

        $this->upsertRepositories($installation, $payload['repositories_added'] ?? []);

        foreach ($payload['repositories_removed'] ?? [] as $repo) {
            Repository::query()
                ->where('installation_id', $installation->id)
                ->where('github_repo_id', (int) ($repo['id'] ?? 0))
                ->update(['removed_at' => now()]);
        }

        return $installation;
    }

    /**
     * Called from the post-install redirect. Returns the installation when the
     * current user is allowed to own it, otherwise null (the webhook may still
     * be on its way, or it belongs to someone else).
     */
    public function claimForUser(User $user, int $githubInstallationId): ?Installation
    {
        $existing = Installation::query()->where('github_installation_id', $githubInstallationId)->first();

        if ($existing !== null) {
            if ($existing->user_id !== $user->id) {
                return null;
            }

            $this->syncRepositoriesFromGitHub($existing);

            return $existing;
        }

        $data = $this->api->getInstallation($githubInstallationId);

        if ((int) ($data['account']['id'] ?? 0) !== $user->github_id) {
            return null;
        }

        $installation = $this->upsertInstallation($user, $data);
        $this->syncRepositoriesFromGitHub($installation);

        return $installation;
    }

    /**
     * Full repository sync from the API. Repositories no longer accessible are
     * marked removed rather than deleted so scan history survives.
     */
    public function syncRepositoriesFromGitHub(Installation $installation): void
    {
        $token = $this->tokens->mint($installation);
        $repos = $this->api->listInstallationRepositories($token);

        $this->upsertRepositories($installation, $repos);

        $seen = array_map(fn (array $repo): int => (int) $repo['id'], $repos);

        Repository::query()
            ->where('installation_id', $installation->id)
            ->whereNotIn('github_repo_id', $seen)
            ->whereNull('removed_at')
            ->update(['removed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $data  A GitHub installation object.
     */
    public function upsertInstallation(User $user, array $data): Installation
    {
        /** @var Installation $installation */
        $installation = Installation::withTrashed()->firstOrNew(['github_installation_id' => (int) $data['id']]);

        $installation->fill([
            'user_id' => $user->id,
            'account_id' => isset($data['account']['id']) ? (int) $data['account']['id'] : null,
            'account_login' => (string) ($data['account']['login'] ?? ''),
            'account_type' => (string) ($data['account']['type'] ?? 'User'),
            'suspended_at' => ! empty($data['suspended_at']) ? CarbonImmutable::parse($data['suspended_at']) : null,
        ]);
        $installation->deleted_at = null;
        $installation->save();

        return $installation;
    }

    /**
     * @param  list<array<string, mixed>>  $repos  GitHub repository objects (webhook or API shape).
     */
    public function upsertRepositories(Installation $installation, array $repos): void
    {
        foreach ($repos as $repo) {
            $githubRepoId = (int) ($repo['id'] ?? 0);

            if ($githubRepoId === 0) {
                continue;
            }

            /** @var Repository $repository */
            $repository = Repository::query()->firstOrNew(['github_repo_id' => $githubRepoId]);
            $repository->fill([
                'installation_id' => $installation->id,
                'full_name' => (string) ($repo['full_name'] ?? $repository->full_name ?? ''),
                'is_private' => (bool) ($repo['private'] ?? false),
                'removed_at' => null,
            ]);

            // Webhook payloads omit default_branch; keep whatever we already know.
            if (! empty($repo['default_branch'])) {
                $repository->default_branch = (string) $repo['default_branch'];
            }

            $repository->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function created(array $payload): ?Installation
    {
        $senderId = (int) ($payload['sender']['id'] ?? 0);
        $user = User::query()->where('github_id', $senderId)->first();

        if ($user === null) {
            Log::info('Ignoring installation.created from a GitHub user who has not signed in.', [
                'sender_id' => $senderId,
                'installation_id' => $payload['installation']['id'] ?? null,
            ]);

            return null;
        }

        $installation = $this->upsertInstallation($user, $payload['installation']);
        $this->upsertRepositories($installation, $payload['repositories'] ?? []);

        return $installation;
    }

    private function deleted(int $githubId): ?Installation
    {
        $installation = Installation::query()->where('github_installation_id', $githubId)->first();

        if ($installation === null) {
            return null;
        }

        $installation->repositories()->whereNull('removed_at')->update(['removed_at' => now()]);
        $installation->delete();

        return $installation;
    }

    private function setSuspended(int $githubId, bool $suspended): ?Installation
    {
        $installation = Installation::query()->where('github_installation_id', $githubId)->first();

        $installation?->forceFill(['suspended_at' => $suspended ? now() : null])->save();

        return $installation;
    }
}
