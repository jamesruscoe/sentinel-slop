<?php

namespace App\Services\GitHub;

use App\Models\WebhookDelivery;

/**
 * Routes verified GitHub webhook deliveries to the sync service, once each.
 */
final class WebhookHandler
{
    public const RESULT_OK = 'ok';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_IGNORED = 'ignored';

    public function __construct(private readonly InstallationSyncService $sync) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(?string $event, ?string $deliveryId, array $payload): string
    {
        $delivery = null;

        if ($deliveryId !== null && $deliveryId !== '') {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                ['delivery_id' => $deliveryId],
                ['event' => (string) $event, 'action' => isset($payload['action']) ? (string) $payload['action'] : null],
            );

            if (! $delivery->wasRecentlyCreated) {
                return self::RESULT_DUPLICATE;
            }
        }

        $handled = match ($event) {
            'installation' => $this->sync->handleInstallationEvent($payload) !== null || true,
            'installation_repositories' => $this->sync->handleRepositoriesEvent($payload) !== null || true,
            default => false,
        };

        $delivery?->forceFill(['processed_at' => now()])->save();

        return $handled ? self::RESULT_OK : self::RESULT_IGNORED;
    }
}
