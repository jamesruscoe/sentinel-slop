<?php

declare(strict_types=1);

namespace App\Scanning\Process;

/**
 * Builds the environment for an analyser process: every inherited variable is
 * removed except a small allowlist the tools need to start, then the spec's
 * own variables are added. App secrets from .env never reach a child process.
 */
final class EnvironmentAllowlist
{
    private const KEEP = [
        'PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'SYSTEMDRIVE', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR',
        'HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'PROGRAMFILES',
        'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE',
        'USER', 'USERNAME', 'SHELL', 'PYTHONIOENCODING',
    ];

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string|false> Variables to set; `false` removes an inherited one.
     */
    public static function build(array $extra = []): array
    {
        $env = [];

        foreach (self::inherited() as $name => $value) {
            $env[$name] = in_array(strtoupper($name), self::KEEP, true) ? $value : false;
        }

        $env['NO_COLOR'] = '1';
        $env['CI'] = '1';
        $env['SEMGREP_SEND_METRICS'] = 'off';
        $env['PYTHONIOENCODING'] = 'utf-8';

        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }

        return $env;
    }

    /**
     * @return array<string, string>
     */
    private static function inherited(): array
    {
        $all = [];

        foreach ([$_SERVER, $_ENV, getenv()] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $all[$name] = $value;
                }
            }
        }

        return $all;
    }
}
