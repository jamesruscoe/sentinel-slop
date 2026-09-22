<?php

use App\Models\Installation;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;

function installationPayload(User $sender, int $installationId = 555, array $repos = []): array
{
    return [
        'action' => 'created',
        'installation' => [
            'id' => $installationId,
            'account' => ['id' => 9001, 'login' => 'acme', 'type' => 'Organization'],
        ],
        'repositories' => $repos ?: [
            ['id' => 1, 'full_name' => 'acme/web', 'private' => true],
            ['id' => 2, 'full_name' => 'acme/api', 'private' => false],
        ],
        'sender' => ['id' => $sender->github_id, 'login' => $sender->username],
    ];
}

test('it rejects deliveries with a missing or invalid signature', function () {
    $this->postJson(route('webhooks.github'), ['action' => 'created'], ['X-GitHub-Event' => 'installation'])
        ->assertStatus(401);

    githubWebhook('installation', ['action' => 'created'], secret: 'wrong-secret')
        ->assertStatus(401);

    expect(WebhookDelivery::count())->toBe(0);
});

test('installation.created links the installation and repositories to the sender', function () {
    $user = User::factory()->create();

    githubWebhook('installation', installationPayload($user), 'delivery-1')->assertStatus(202);

    $installation = Installation::where('github_installation_id', 555)->firstOrFail();
    expect($installation->user_id)->toBe($user->id)
        ->and($installation->account_login)->toBe('acme')
        ->and($installation->account_type)->toBe('Organization')
        ->and($installation->repositories()->pluck('full_name')->all())->toBe(['acme/web', 'acme/api'])
        ->and(WebhookDelivery::where('delivery_id', 'delivery-1')->value('processed_at'))->not->toBeNull();
});

test('installation.created from a user who never signed in is ignored', function () {
    githubWebhook('installation', [
        'action' => 'created',
        'installation' => ['id' => 777, 'account' => ['id' => 1, 'login' => 'x', 'type' => 'User']],
        'sender' => ['id' => 424242],
    ])->assertStatus(202);

    expect(Installation::count())->toBe(0);
});

test('duplicate deliveries are processed once', function () {
    $user = User::factory()->create();

    githubWebhook('installation', installationPayload($user), 'dup-1')->assertStatus(202);
    githubWebhook('installation', installationPayload($user), 'dup-1')->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(WebhookDelivery::count())->toBe(1)->and(Installation::count())->toBe(1);
});

test('installation_repositories adds and removes repositories', function () {
    $installation = Installation::factory()->create(['github_installation_id' => 555]);
    $existing = Repository::factory()->for($installation)->create(['github_repo_id' => 1, 'full_name' => 'acme/web']);

    githubWebhook('installation_repositories', [
        'action' => 'added',
        'installation' => ['id' => 555],
        'repositories_added' => [['id' => 3, 'full_name' => 'acme/mobile', 'private' => true]],
        'repositories_removed' => [['id' => 1, 'full_name' => 'acme/web']],
    ])->assertStatus(202);

    expect($existing->fresh()->removed_at)->not->toBeNull()
        ->and(Repository::where('github_repo_id', 3)->value('installation_id'))->toBe($installation->id);
});

test('installation deleted soft-deletes it and marks its repositories removed', function () {
    $installation = Installation::factory()->create(['github_installation_id' => 555]);
    $repo = Repository::factory()->for($installation)->create();

    githubWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 555]])->assertStatus(202);

    expect(Installation::find($installation->id))->toBeNull()
        ->and(Installation::withTrashed()->find($installation->id))->not->toBeNull()
        ->and($repo->fresh()->removed_at)->not->toBeNull();
});

test('suspend and unsuspend toggle suspended_at', function () {
    $installation = Installation::factory()->create(['github_installation_id' => 555]);

    githubWebhook('installation', ['action' => 'suspend', 'installation' => ['id' => 555]])->assertStatus(202);
    expect($installation->fresh()->isSuspended())->toBeTrue();

    githubWebhook('installation', ['action' => 'unsuspend', 'installation' => ['id' => 555]])->assertStatus(202);
    expect($installation->fresh()->isSuspended())->toBeFalse();
});

test('re-installing restores a previously deleted installation', function () {
    $user = User::factory()->create();
    $installation = Installation::factory()->for($user)->create(['github_installation_id' => 555]);
    $installation->delete();

    githubWebhook('installation', installationPayload($user))->assertStatus(202);

    expect(Installation::where('github_installation_id', 555)->count())->toBe(1);
});

test('unrelated events are acknowledged but ignored', function () {
    githubWebhook('ping', ['zen' => 'Keep it logically awesome.'])->assertStatus(200)->assertJson(['status' => 'ignored']);
});
