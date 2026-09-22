<?php

declare(strict_types=1);

namespace App\Scanning\Support;

final class FileWalker
{
    /**
     * Walk a directory without following symlinks. Symlinks (to files or
     * directories) are reported, never traversed.
     *
     * @return list<array{path: string, absolute: string, is_link: bool, size: int}>
     */
    public static function walk(string $root): array
    {
        $entries = [];
        self::walkInto(rtrim($root, '/'.chr(92)), '', $entries);

        return $entries;
    }

    /**
     * @param  list<array{path: string, absolute: string, is_link: bool, size: int}>  $entries
     */
    private static function walkInto(string $root, string $relative, array &$entries): void
    {
        $directory = $relative === '' ? $root : $root.'/'.$relative;
        $names = scandir($directory, SCANDIR_SORT_ASCENDING);

        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relativePath = $relative === '' ? $name : $relative.'/'.$name;
            $absolute = $root.'/'.$relativePath;

            if (is_link($absolute)) {
                $entries[] = ['path' => $relativePath, 'absolute' => $absolute, 'is_link' => true, 'size' => 0];

                continue;
            }

            if (is_dir($absolute)) {
                self::walkInto($root, $relativePath, $entries);

                continue;
            }

            $size = filesize($absolute);
            $entries[] = ['path' => $relativePath, 'absolute' => $absolute, 'is_link' => false, 'size' => $size === false ? 0 : $size];
        }
    }
}
