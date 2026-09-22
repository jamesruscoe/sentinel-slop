<?php

namespace App\Services\GitHub;

use SensitiveParameter;

final class WebhookSignatureVerifier
{
    public function __construct(#[SensitiveParameter] private readonly ?string $secret) {}

    /**
     * Verify the X-Hub-Signature-256 header for a raw payload. Fails closed
     * when no secret is configured.
     */
    public function verify(string $payload, ?string $signatureHeader): bool
    {
        if ($this->secret === null || $this->secret === '' || $signatureHeader === null) {
            return false;
        }

        $expected = self::sign($payload, $this->secret);

        return hash_equals($expected, $signatureHeader);
    }

    public static function sign(string $payload, #[SensitiveParameter] string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }
}
