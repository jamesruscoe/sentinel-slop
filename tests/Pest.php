<?php

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
