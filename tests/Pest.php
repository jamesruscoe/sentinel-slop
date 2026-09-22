<?php

use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Support\FileWalker;
use App\Services\GitHub\WebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Post a GitHub webhook with a valid signature.
 *
 * @param  array<string, mixed>  $payload
 */
function githubWebhook(string $event, array $payload, ?string $deliveryId = null, ?string $secret = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret ??= (string) config('services.github.webhook_secret');

    return test()->call('POST', route('webhooks.github'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId ?? (string) Str::uuid(),
        'HTTP_X_HUB_SIGNATURE_256' => WebhookSignatureVerifier::sign($body, $secret),
    ], $body);
}

/**
 * A fresh, empty scan workspace under the system temp directory. Deleted
 * automatically when the test ends.
 */
function temporaryWorkspace(): ScanWorkspace
{
    $base = sys_get_temp_dir().'/sentinel-tests';
    if (! is_dir($base)) {
        mkdir($base, 0700, true);
    }

    $workspace = ScanWorkspace::create($base, 'ws-'.bin2hex(random_bytes(6)));
    test()->workspacesToDelete[] = $workspace;

    return $workspace;
}

/**
 * Copy a fixture repository's files into a workspace's repo directory.
 */
function workspaceFromFixture(string $fixture): ScanWorkspace
{
    $workspace = temporaryWorkspace();
    $source = fixturePath($fixture);

    foreach (FileWalker::walk($source) as $entry) {
        $target = $workspace->repoPath().'/'.$entry['path'];
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        copy($entry['absolute'], $target);
    }

    return $workspace;
}

function fixturePath(string $fixture): string
{
    return __DIR__.'/Fixtures/repos/'.$fixture;
}
