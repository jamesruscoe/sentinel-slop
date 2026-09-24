<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Support\FileWalker;

/**
 * Enumerates the source files a heuristic should look at.
 */
final class SourceFiles
{
    public const PHP = ['php'];

    public const JS = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'mts', 'cts', 'vue', 'svelte'];

    public const PYTHON = ['py'];

    /**
     * @param  list<string>  $extensions
     * @return list<array{path: string, absolute: string, size: int}>
     */
    public static function in(string $repoPath, array $extensions): array
    {
        $files = [];

        foreach (FileWalker::walk($repoPath) as $entry) {
            if ($entry['is_link']) {
                continue;
            }

            $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
            if (! in_array($extension, $extensions, true) || str_ends_with($entry['path'], '.blade.php')) {
                continue;
            }

            $files[] = ['path' => $entry['path'], 'absolute' => $entry['absolute'], 'size' => $entry['size']];
        }

        return $files;
    }

    public static function isTestFile(string $relativePath): bool
    {
        $path = strtolower($relativePath);

        return preg_match('~(^|/)(tests?|__tests__|__mocks__|spec|specs|fixtures?|factories|seeders?|seeds?|stories|mocks?|stubs?|examples?)(/|$)~', $path) === 1
            || preg_match('~[.](test|spec|stories)[.][a-z]+$~', $path) === 1
            || str_ends_with($path, 'test.php');
    }

    /**
     * Files a tool copies into other projects (CLI scaffolds, Laravel stubs,
     * cookiecutter templates): variants that must stand alone, importing
     * packages the generated project declares. Not this repository's code
     * in the ordinary sense.
     */
    public static function isTemplateFile(string $relativePath): bool
    {
        return preg_match('~(^|/)(templates?|stubs?|scaffolds?|skeletons?|boilerplate|cookiecutter[^/]*)(/|$)~i', $relativePath) === 1
            && ! str_contains(strtolower($relativePath), 'resources/views');
    }

    /**
     * @return list<string>
     */
    public static function lines(string $absolutePath): array
    {
        $contents = @file_get_contents($absolutePath);

        return $contents === false ? [] : (preg_split('/\r\n|\r|\n/', $contents) ?: []);
    }

    public static function extensionOf(string $relativePath): string
    {
        return strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    }
}
