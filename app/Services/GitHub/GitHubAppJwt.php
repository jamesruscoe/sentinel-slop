<?php

namespace App\Services\GitHub;

use App\Exceptions\GitHubAppNotConfiguredException;
use Firebase\JWT\JWT;

/**
 * Signs the RS256 JWT a GitHub App uses to authenticate as itself.
 * The private key is read lazily so an unconfigured app only fails when
 * the JWT is actually needed.
 */
final class GitHubAppJwt
{
    public function __construct(
        private readonly string $appId,
        private readonly ?string $privateKeyPath,
        private readonly ?string $privateKeyPem = null,
    ) {}

    /**
     * @param  array{app_id?: string|int|null, private_key_path?: string|null}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self((string) ($config['app_id'] ?? ''), $config['private_key_path'] ?? null);
    }

    public function create(?int $now = null): string
    {
        if ($this->appId === '') {
            throw new GitHubAppNotConfiguredException('GITHUB_APP_ID is not set.');
        }

        $now ??= time();

        return JWT::encode([
            'iat' => $now - 60,
            'exp' => $now + 9 * 60,
            'iss' => $this->appId,
        ], $this->privateKey(), 'RS256');
    }

    private function privateKey(): string
    {
        if ($this->privateKeyPem !== null) {
            return $this->privateKeyPem;
        }

        if ($this->privateKeyPath === null || $this->privateKeyPath === '' || ! is_file($this->privateKeyPath)) {
            throw new GitHubAppNotConfiguredException('GITHUB_APP_PRIVATE_KEY_PATH does not point at a readable private key file.');
        }

        $pem = file_get_contents($this->privateKeyPath);

        if ($pem === false || trim($pem) === '') {
            throw new GitHubAppNotConfiguredException('The GitHub App private key file is empty.');
        }

        return $pem;
    }
}
