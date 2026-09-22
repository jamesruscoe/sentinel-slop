<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGitHubLogin(array $attributes = []): void
{
    $socialiteUser = (new SocialiteUser)->map(array_merge([
        'id' => 4242,
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octo@example.com',
        'avatar' => 'https://avatars.githubusercontent.com/u/4242',
    ], $attributes));

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
}

test('the login route redirects to GitHub', function () {
    $this->get(route('auth.github'))
        ->assertRedirect()
        ->assertRedirectContains('github.com/login/oauth/authorize');
});

test('guests hitting an auth-only page are sent to GitHub sign-in', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('login'))->assertRedirect(route('auth.github'));
});

test('the callback creates the user and signs them in', function () {
    fakeGitHubLogin();

    $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

    $user = User::where('github_id', 4242)->firstOrFail();
    expect($user->username)->toBe('octocat')
        ->and($user->email)->toBe('octo@example.com')
        ->and($user->avatar_url)->toContain('4242');
    $this->assertAuthenticatedAs($user);
});

test('the callback updates an existing user by GitHub id', function () {
    $user = User::factory()->create(['github_id' => 4242, 'username' => 'old-name']);
    fakeGitHubLogin(['nickname' => 'new-name']);

    $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)->and($user->fresh()->username)->toBe('new-name');
});

test('a failed OAuth exchange redirects home with an error', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andThrow(new RuntimeException('bad code'));
    Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

    $this->get(route('auth.github.callback'))->assertRedirect(route('home'))->assertSessionHas('error');
    $this->assertGuest();
});

test('logout ends the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});
