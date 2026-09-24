<?php

namespace App\Providers;

use App\Services\GitHub\GitHubAppApi;
use App\Services\GitHub\GitHubAppJwt;
use App\Services\GitHub\KnpGitHubAppApi;
use App\Services\GitHub\WebhookSignatureVerifier;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\InstallationContentSourceResolver;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerGitHub();
        $this->registerScanning();
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Starting scans is expensive (fetch + analysers + an LLM call): a handful per user per hour.
        RateLimiter::for('scans', fn (Request $request) => Limit::perHour((int) config('sentinel.limits.scans_per_user_per_hour', 10))
            ->by($request->user() !== null ? (string) $request->user()->id : (string) $request->ip())
            ->response(fn () => back()->with('error', 'Too many scans started recently. Please wait a while before starting another.')));
    }

    private function registerGitHub(): void
    {
        $this->app->bind(GitHubAppApi::class, KnpGitHubAppApi::class);

        $this->app->bind(GitHubAppJwt::class, function (): GitHubAppJwt {
            /** @var array{app_id?: string|int|null, private_key_path?: string|null, private_key?: string|null} $config */
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

    private function registerScanning(): void
    {
        $this->app->bind(ContentSourceResolver::class, InstallationContentSourceResolver::class);

        $this->app->bind(ScanWorkspaceFactory::class, function (): ScanWorkspaceFactory {
            $base = str_replace(chr(92), '/', (string) config('sentinel.scan_storage_path'));

            return new ScanWorkspaceFactory(rtrim($base, '/'));
        });
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\/]~', $path) === 1;
    }
}
