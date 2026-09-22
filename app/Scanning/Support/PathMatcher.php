<?php

declare(strict_types=1);

namespace App\Scanning\Support;

final class PathMatcher
{
    /**
     * If the relative path lies under one of the skipped directories, return
     * that directory's relative path (e.g. "packages/foo/vendor"), else null.
     * Single-segment entries match any segment; entries with slashes match
     * as a contiguous sub-path anywhere.
     *
     * @param  list<string>  $skippedDirectories
     */
    public static function skippedDirectoryFor(string $relativePath, array $skippedDirectories): ?string
    {
        $path = trim(str_replace(chr(92), '/', $relativePath), '/');
        $segments = explode('/', $path);
        $directorySegments = array_slice($segments, 0, -1);

        foreach ($skippedDirectories as $entry) {
            $entry = trim(str_replace(chr(92), '/', $entry), '/');
            if ($entry === '') {
                continue;
            }

            $entrySegments = explode('/', $entry);
            $window = count($entrySegments);

            for ($i = 0; $i + $window <= count($directorySegments); $i++) {
                if (array_slice($directorySegments, $i, $window) === $entrySegments) {
                    return implode('/', array_slice($directorySegments, 0, $i + $window));
                }
            }
        }

        return null;
    }

    /**
     * Minimal glob matching for basenames: `*` and `?` only. Case-insensitive.
     */
    public static function matchesGlob(string $basename, string $pattern): bool
    {
        $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';

        return preg_match($regex, $basename) === 1;
    }
}
