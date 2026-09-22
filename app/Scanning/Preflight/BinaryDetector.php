<?php

declare(strict_types=1);

namespace App\Scanning\Preflight;

use App\Scanning\Enums\SkipReason;

/**
 * Detects binaries and native executables by content, never by extension.
 */
final class BinaryDetector
{
    private const EXECUTABLE_MAGIC = [
        "\x7fELF",          // ELF
        'MZ',               // PE / DOS
        "\xCA\xFE\xBA\xBE", // Mach-O fat / Java class
        "\xFE\xED\xFA\xCE", // Mach-O 32
        "\xFE\xED\xFA\xCF", // Mach-O 64
        "\xCE\xFA\xED\xFE", // Mach-O 32 LE
        "\xCF\xFA\xED\xFE", // Mach-O 64 LE
        "\x00asm",          // WebAssembly
    ];

    public static function detect(string $absolutePath): ?SkipReason
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return SkipReason::Binary;
        }

        $head = (string) fread($handle, 8192);
        fclose($handle);

        return self::detectFromBytes($head);
    }

    public static function detectFromBytes(string $head): ?SkipReason
    {
        if ($head === '') {
            return null;
        }

        foreach (self::EXECUTABLE_MAGIC as $magic) {
            if (str_starts_with($head, $magic)) {
                return SkipReason::Executable;
            }
        }

        if (str_contains($head, "\0")) {
            return SkipReason::Binary;
        }

        // Control characters other than the usual whitespace/escape bytes.
        $control = preg_match_all('/[\x00-\x08\x0E-\x1A\x1C-\x1F]/', $head);
        if ($control !== false && $control > strlen($head) * 0.10) {
            return SkipReason::Binary;
        }

        return null;
    }
}
