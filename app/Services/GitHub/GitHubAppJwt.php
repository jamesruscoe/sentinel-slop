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
     * `private_key` is the PEM itself, or the PEM base64-encoded (a single line, the shape a
     * secrets manager injects into an environment variable). It takes precedence over the path.
     *
     * @param  array{app_id?: string|int|null, private_key_path?: string|null, private_key?: string|null}  $config
     */
    public static function fromConfig(array $config): self
    {
        $key = $config['private_key'] ?? null;
        $pem = null;
        if (is_string($key) && trim($key) !== '') {
            $decoded = str_contains($key, '-----BEGIN') ? trim($key) : base64_decode(trim($key), true);
            if ($decoded === false || ! str_contains($decoded, '-----BEGIN')) {
                throw new GitHubAppNotConfiguredException('GITHUB_APP_PRIVATE_KEY must be the PEM private key, raw or base64-encoded.');
            }
            $pem = $decoded;
        }

        return new self((string) ($config['app_id'] ?? ''), $config['private_key_path'] ?? null, $pem);
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
