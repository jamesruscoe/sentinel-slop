<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

/**
 * Finds npm packages imported by JS/TS files that package.json does not declare.
 */
final class NpmImportScanner
{
    private const NODE_BUILTINS = ['assert', 'async_hooks', 'buffer', 'child_process', 'cluster', 'console', 'constants', 'crypto', 'dgram', 'diagnostics_channel', 'dns', 'domain', 'events', 'fs', 'http', 'http2', 'https', 'inspector', 'module', 'net', 'os', 'path', 'perf_hooks', 'process', 'punycode', 'querystring', 'readline', 'repl', 'stream', 'string_decoder', 'sys', 'timers', 'tls', 'trace_events', 'tty', 'url', 'util', 'v8', 'vm', 'wasi', 'worker_threads', 'zlib', 'test'];

    /**
     * @param  array<string, mixed>  $package  Decoded package.json.
     * @param  array<string, mixed>|null  $tsconfig  Decoded tsconfig.json, if any.
     * @return list<array{package: string, file: string, line: int}>
     */
    public function scan(string $repoPath, array $package, ?array $tsconfig): array
    {
        $declared = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            foreach (array_keys(is_array($package[$section] ?? null) ? $package[$section] : []) as $name) {
                $declared[strtolower((string) $name)] = true;
            }
        }

        $aliases = ['#', '@/', '~/', '$'];
        foreach (array_keys(is_array($tsconfig['compilerOptions']['paths'] ?? null) ? $tsconfig['compilerOptions']['paths'] : []) as $alias) {
            $aliases[] = rtrim((string) $alias, '*');
        }
        foreach (array_keys(is_array($package['imports'] ?? null) ? $package['imports'] : []) as $alias) {
            $aliases[] = rtrim((string) $alias, '*');
        }

        /** @var array<string, array{package: string, file: string, line: int}> $unknown */
        $unknown = [];

        foreach (SourceFiles::in($repoPath, SourceFiles::JS) as $file) {
            foreach (SourceFiles::lines($file['absolute']) as $index => $line) {
                if (preg_match_all('~(?:from\s*|import\s*\(?\s*|require\s*\(\s*)["\']([^"\'\s]+)["\']~', $line, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $specifier) {
                    $name = self::packageName($specifier, $aliases);
                    if ($name !== null && ! isset($declared[$name])) {
                        $unknown[$name] ??= ['package' => $name, 'file' => $file['path'], 'line' => $index + 1];
                    }
                }
            }
        }

        return array_values($unknown);
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function packageName(string $specifier, array $aliases): ?string
    {
        if ($specifier === '' || str_starts_with($specifier, '.') || str_starts_with($specifier, '/') || str_starts_with($specifier, 'node:') || str_contains($specifier, '://') || str_starts_with($specifier, 'virtual:')) {
            return null;
        }

        foreach ($aliases as $alias) {
            if ($alias !== '' && str_starts_with($specifier, $alias)) {
                return null;
            }
        }

        $parts = explode('/', $specifier);
        $name = strtolower(str_starts_with($specifier, '@') && count($parts) >= 2 ? $parts[0].'/'.$parts[1] : $parts[0]);

        if (in_array($name, self::NODE_BUILTINS, true) || str_contains($name, '.') || preg_match('/^[a-z0-9@][a-z0-9_\/-]*$/', $name) !== 1) {
            return null;
        }

        return $name;
    }
}
