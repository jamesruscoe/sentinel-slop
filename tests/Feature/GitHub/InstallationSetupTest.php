<?php

use App\Models\Installation;
use App\Models\Repository;
use App\Models\User;
use App\Services\GitHub\GitHubAppApi;
use Tests\Support\FakeGitHubAppApi;

beforeEach(function () {
    $this->api = new FakeGitHubAppApi;
    app()->instance(GitHubAppApi::class, $this->api);
});

test('install redirects to the GitHub App installation page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('github.install'))
        ->assertRedirect('https://github.com/apps/sentinel-slop-test/installations/new');
});

test('setup claims a personal installation when the webhook has not arrived yet', function () {
    $user = User::factory()->create(['github_id' => 4242]);
    $this->api->installations[555] = ['id' => 555, 'account' => ['id' => 4242, 'login' => 'octocat', 'type' => 'User']];
    $this->api->repositories = [
        ['id' => 10, 'full_name' => 'octocat/hello', 'private' => false, 'default_branch' => 'main'],
    ];

    $this->actingAs($user)
        ->get(route('github.setup', ['installation_id' => 555, 'setup_action' => 'install']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    $installation = Installation::where('github_installation_id', 555)->firstOrFail();
    expect($installation->user_id)->toBe($user->id)
        ->and($installation->repositories()->first()?->default_branch)->toBe('main')
        ->and($this->api->tokensMinted)->toBe(1);
});

test('setup shows a waiting page for an organisation installation the webhook has not linked yet', function () {
    $user = User::factory()->create(['github_id' => 4242]);
    $this->api->installations[555] = ['id' => 555, 'account' => ['id' => 9001, 'login' => 'acme', 'type' => 'Organization']];

    $this->actingAs($user)
        ->get(route('github.setup', ['installation_id' => 555]))
        ->assertOk()
        ->assertSee('Waiting for GitHub');

    expect(Installation::count())->toBe(0);
});

test('setup re-syncs repositories for an installation the user already owns', function () {
    $user = User::factory()->create();
    $installation = Installation::factory()->for($user)->create(['github_installation_id' => 555]);
    $gone = Repository::factory()->for($installation)->create(['github_repo_id' => 1]);
    $this->api->repositories = [['id' => 2, 'full_name' => 'acme/new', 'private' => true, 'default_branch' => 'develop']];

    $this->actingAs($user)->get(route('github.setup', ['installation_id' => 555]))->assertRedirect(route('dashboard'));

    expect($gone->fresh()->removed_at)->not->toBeNull()
        ->and(Repository::where('github_repo_id', 2)->value('default_branch'))->toBe('develop');
});

test('setup never hands over an installation owned by someone else', function () {
    Installation::factory()->create(['github_installation_id' => 555]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->get(route('github.setup', ['installation_id' => 555]))->assertOk()->assertSee('Waiting for GitHub');

    expect(Installation::where('github_installation_id', 555)->value('user_id'))->not->toBe($intruder->id);
});

test('a pending organisation request is explained', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('github.setup', ['installation_id' => 555, 'setup_action' => 'request']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');
});
