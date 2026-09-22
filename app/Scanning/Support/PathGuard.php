<?php

declare(strict_types=1);

namespace App\Scanning\Support;

use App\Scanning\Exceptions\UnsafePathException;

final class PathGuard
{
    /**
     * Validate a repository-relative path as delivered by the tree API and
     * return it normalised (forward slashes). Anything that could escape the
     * workspace or cannot be written safely is rejected outright.
     */
    public static function assertSafeRelativePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new UnsafePathException('The repository contains an empty or NUL-containing path.');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, chr(92)) || preg_match('~^[A-Za-z]:~', $path) === 1) {
            throw new UnsafePathException("The repository contains an absolute path: {$path}");
        }

        if (str_contains($path, chr(92))) {
            throw new UnsafePathException("The repository contains a path with a backslash: {$path}");
        }

        if (preg_match('/[\x00-\x1F<>:"|?*]/', $path) === 1) {
            throw new UnsafePathException("The repository contains a path with unsupported characters: {$path}");
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UnsafePathException("The repository contains a path that could escape the scan directory: {$path}");
            }

            if (strcasecmp($segment, '.git') === 0) {
                throw new UnsafePathException("The repository contains a .git path entry: {$path}");
            }
        }

        return $path;
    }

    /**
     * Whether $path resolves to a location inside $root. Both must exist.
     */
    public static function isInside(string $root, string $path): bool
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false) {
            return false;
        }

        $realRoot = rtrim(str_replace(chr(92), '/', $realRoot), '/').'/';
        $realPath = str_replace(chr(92), '/', $realPath);

        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($realPath.'/', $realRoot, strlen($realRoot)) === 0
            : strncmp($realPath.'/', $realRoot, strlen($realRoot)) === 0;
    }
}
