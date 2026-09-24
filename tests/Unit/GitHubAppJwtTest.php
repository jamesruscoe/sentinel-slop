<?php

use App\Exceptions\GitHubAppNotConfiguredException;
use App\Services\GitHub\GitHubAppJwt;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// A throwaway RSA key pair used only by tests. It is not registered with any GitHub App.
const TEST_PRIVATE_KEY = __DIR__.'/../Fixtures/keys/test-github-app.pem';
const TEST_PUBLIC_KEY = __DIR__.'/../Fixtures/keys/test-github-app.pub';

test('it signs an RS256 JWT with the GitHub App claims', function () {
    $now = time();

    $token = (new GitHubAppJwt('12345', null, file_get_contents(TEST_PRIVATE_KEY)))->create($now);
    $claims = (array) JWT::decode($token, new Key(file_get_contents(TEST_PUBLIC_KEY), 'RS256'));

    expect($claims)->toBe(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => '12345']);
});

test('it reads the private key from a file path', function () {
    $token = GitHubAppJwt::fromConfig(['app_id' => 99, 'private_key_path' => TEST_PRIVATE_KEY])->create();

    expect((array) JWT::decode($token, new Key(file_get_contents(TEST_PUBLIC_KEY), 'RS256')))->toHaveKey('iss', '99');
});

test('it reads the private key from an environment value, raw or base64, ahead of the path', function () {
    $pem = (string) file_get_contents(TEST_PRIVATE_KEY);
    $public = new Key(file_get_contents(TEST_PUBLIC_KEY), 'RS256');

    $fromBase64 = GitHubAppJwt::fromConfig(['app_id' => 7, 'private_key_path' => '/nope/missing.pem', 'private_key' => base64_encode($pem)])->create();
    $fromRaw = GitHubAppJwt::fromConfig(['app_id' => 8, 'private_key' => $pem])->create();

    expect((array) JWT::decode($fromBase64, $public))->toHaveKey('iss', '7')
        ->and((array) JWT::decode($fromRaw, $public))->toHaveKey('iss', '8');

    expect(fn () => GitHubAppJwt::fromConfig(['app_id' => 9, 'private_key' => 'not a key']))
        ->toThrow(GitHubAppNotConfiguredException::class, 'GITHUB_APP_PRIVATE_KEY must be');
});

test('it fails clearly when the app is not configured', function () {
    expect(fn () => (new GitHubAppJwt('', null))->create())
        ->toThrow(GitHubAppNotConfiguredException::class, 'GITHUB_APP_ID');

    expect(fn () => (new GitHubAppJwt('1', '/nope/missing.pem'))->create())
        ->toThrow(GitHubAppNotConfiguredException::class, 'GITHUB_APP_PRIVATE_KEY_PATH');
});
