<?php

namespace App\Providers;

use App\Services\GitHub\GitHubAppApi;
use App\Services\GitHub\GitHubAppJwt;
use App\Services\GitHub\KnpGitHubAppApi;
use App\Services\GitHub\WebhookSignatureVerifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GitHubAppApi::class, KnpGitHubAppApi::class);

        $this->app->bind(GitHubAppJwt::class, function (): GitHubAppJwt {
            /** @var array{app_id?: string|int|null, private_key_path?: string|null} $config */
            $config = config('services.github', []);
            $path = $config['private_key_path'] ?? null;

            if (is_string($path) && $path !== '' && ! self::isAbsolutePath($path)) {
                $config['private_key_path'] = base_path($path);
            }

            return GitHubAppJwt::fromConfig($config);
        });

        $this->app->bind(WebhookSignatureVerifier::class, function (): WebhookSignatureVerifier {
            $secret = config('services.github.webhook_secret');

            return new WebhookSignatureVerifier(is_string($secret) ? $secret : null);
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\/]~', $path) === 1;
    }
}
