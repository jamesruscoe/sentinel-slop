<?php

declare(strict_types=1);

namespace App\Scanning\Normalise;

use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;
use SensitiveParameter;

/**
 * Makes sure secret values never reach the database or the LLM. Findings in
 * the Secrets category keep only file, line and rule/type: the snippet is
 * dropped and the message is scrubbed. Everything else is scrubbed by
 * context (secret-ish key names) and by shape (known token prefixes, or
 * long mixed-case-with-digits / hex strings), so identifiers, paths and
 * class names in snippets survive.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    private const CONTEXT = '/((?:api[_-]?key|secret|token|password|passwd|pwd|auth(?:orization)?|bearer|credential|private[_-]?key|client[_-]?secret)\\s*["\\\']?\\s*(?:=>|=|:)\\s*["\\\']?\\s*(?:bearer\\s+|basic\\s+)?)([^"\\\'\\s,;)]{6,})/i';

    private const PREFIXES = '/\b(?:sk-[A-Za-z0-9_-]{16,}|(?:sk|rk|pk)_(?:live|test|prod)_[A-Za-z0-9]{10,}|gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[A-Z0-9]{16}|xox[abprs]-[A-Za-z0-9-]{10,}|AIza[A-Za-z0-9_-]{30,}|ya29\.[A-Za-z0-9_-]{20,}|eyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{5,}\.?[A-Za-z0-9_-]*)/';

    private const TOKEN = '/\b[A-Za-z0-9_\-+\/]{20,}={0,2}\b/';

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
     * Remove anything that looks like a credential without touching ordinary
     * identifiers, paths and class names.
     */
    public static function scrub(string $text): string
    {
        $text = preg_replace(self::CONTEXT, '$1'.self::PLACEHOLDER, $text) ?? $text;
        $text = preg_replace(self::PREFIXES, self::PLACEHOLDER, $text) ?? $text;

        return preg_replace_callback(self::TOKEN, fn (array $m) => self::looksLikeSecret($m[0]) ? self::PLACEHOLDER : $m[0], $text) ?? $text;
    }

    /**
     * Long strings mixing upper, lower and digits (API keys, base64), or long
     * hex strings (hashes, hex keys). Identifiers like OrderShipmentNotification
     * have no digits; paths have no digits or only a few.
     */
    public static function looksLikeSecret(string $token): bool
    {
        $token = rtrim($token, '=');
        $length = strlen($token);

        if ($length >= 32 && preg_match('/^[0-9a-f]+$/i', $token) === 1) {
            return true;
        }

        if ($length < 20 || preg_match_all('/[0-9]/', $token) < 3) {
            return false;
        }

        $hasUpper = preg_match('/[A-Z]/', $token) === 1;
        $hasLower = preg_match('/[a-z]/', $token) === 1;
        $isPathLike = str_contains($token, '/') && preg_match('/[a-z]{4,}\/[a-z]{4,}/i', $token) === 1;

        return $hasUpper && $hasLower && ! $isPathLike;
    }
}
