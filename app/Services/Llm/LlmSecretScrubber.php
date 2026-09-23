<?php

namespace App\Services\Llm;

use App\Scanning\Normalise\SecretRedactor;

/**
 * Belt and braces for provider API keys: any configured Prism provider key
 * is replaced wherever it appears in text that is about to be logged or
 * stored (exception messages, synthesis_error). The key itself is only ever
 * read here and by Prism; it never sits on a job, a model or a DTO.
 */
final class LlmSecretScrubber
{
    public static function scrub(string $text): string
    {
        foreach ((array) config('prism.providers', []) as $provider) {
            $key = is_array($provider) ? ($provider['api_key'] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $text = SecretRedactor::redactValue($text, $key);
            }
        }

        return SecretRedactor::scrub($text);
    }
}
