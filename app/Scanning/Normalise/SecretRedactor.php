<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;
use SensitiveParameter;

/**
 * Makes sure secret values never reach the database or the LLM. Findings in
 * the Secrets category keep only file, line and rule/type: the snippet is
 * dropped and the message is scrubbed of anything token-shaped.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    public function redact(Finding $finding): Finding
    {
        if ($finding->category !== FindingCategory::Secrets) {
            return $finding;
        }

        return $finding->with([
            'message' => self::scrub($finding->message),
            'snippet' => null,
        ]);
    }

    /**
     * Replace a known secret value wherever it appears.
     */
    public static function redactValue(string $text, #[SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, self::PLACEHOLDER, $text);
    }

    /**
     * Remove anything that looks like a credential: long unbroken tokens and
     * quoted values after assignment-style separators.
     */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/(["\'])([^"\'\s]{8,})\1/', '$1'.self::PLACEHOLDER.'$1', $text) ?? $text;
        $text = preg_replace('/([=:]\s*)([A-Za-z0-9_\-+\/.]{12,}={0,2})/', '$1'.self::PLACEHOLDER, $text) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9_\-+\/]{24,}={0,2}\b/', self::PLACEHOLDER, $text) ?? $text;

        return $text;
    }
}
