<?php

declare(strict_types=1);

namespace App\Scanning\Score;

/**
 * Counts non-blank, non-comment-only lines across the analysable source files.
 */
final class LinesOfCodeCounter
{
    private const EXTENSIONS = ['php', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte', 'py', 'rb', 'go', 'java', 'kt', 'cs', 'rs', 'swift', 'blade.php'];

    /**
     * @param  list<string>  $files  Relative paths that survived preflight.
     */
    public static function count(string $repoPath, array $files): int
    {
        $total = 0;

        foreach ($files as $relative) {
            if (! self::isCode($relative)) {
                continue;
            }

            $contents = @file_get_contents(rtrim($repoPath, '/').'/'.$relative);
            if ($contents === false) {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || preg_match('~^(//|#|/\*|\*|\*/|<!--)~', $trimmed) === 1) {
                    continue;
                }
                $total++;
            }
        }

        return $total;
    }

    public static function isCode(string $relativePath): bool
    {
        $lower = strtolower($relativePath);
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($lower, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }
}
