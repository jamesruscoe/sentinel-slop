<?php

declare(strict_types=1);

namespace App\Scanning\Process;

use App\Scanning\Exceptions\AnalyserUnavailableException;

/**
 * Resolves each analyser to an argv prefix and checks it is installed.
 * Paths come from config; bare names are looked up on PATH.
 */
final class ToolLocator
{
    public const TOOLS = ['phpstan', 'pint', 'eslint', 'jscpd', 'semgrep', 'gitleaks'];

    /**
     * @param  array<string, mixed>  $tools  The `sentinel.tools` config array.
     */
    public function __construct(private readonly array $tools) {}

    /**
     * @return list<string>
     */
    public function command(string $tool): array
    {
        return match ($tool) {
            'phpstan' => [$this->binary('php'), $this->file('phpstan')],
            'pint' => [$this->binary('php'), $this->file('pint')],
            'eslint' => [$this->binary('node'), $this->file('eslint')],
            'jscpd' => [$this->binary('node'), $this->file('jscpd')],
            'semgrep' => [$this->binary('semgrep')],
            'gitleaks' => [$this->binary('gitleaks')],
            default => throw new AnalyserUnavailableException("Unknown analyser tool: {$tool}"),
        };
    }

    public function isAvailable(string $tool): bool
    {
        try {
            $this->command($tool);

            return true;
        } catch (AnalyserUnavailableException) {
            return false;
        }
    }

    /**
     * @return array<string, array{available: bool, command: list<string>|null, error: string|null}>
     */
    public function report(): array
    {
        $report = [];
        foreach (self::TOOLS as $tool) {
            try {
                $report[$tool] = ['available' => true, 'command' => $this->command($tool), 'error' => null];
            } catch (AnalyserUnavailableException $e) {
                $report[$tool] = ['available' => false, 'command' => null, 'error' => $e->getMessage()];
            }
        }

        return $report;
    }

    private function file(string $tool): string
    {
        $path = (string) ($this->tools[$tool] ?? '');

        if ($path === '' || ! is_file($path)) {
            throw new AnalyserUnavailableException("The {$tool} script is missing at \"{$path}\". Run composer install / npm install, or set SENTINEL_".strtoupper($tool).'_PATH.');
        }

        return $path;
    }

    private function binary(string $tool): string
    {
        $configured = (string) ($this->tools[$tool] ?? $tool);

        if ($configured === '') {
            throw new AnalyserUnavailableException("No binary configured for {$tool}.");
        }

        if (str_contains($configured, '/') || str_contains($configured, chr(92))) {
            if (! is_file($configured)) {
                throw new AnalyserUnavailableException("The {$tool} binary is missing at \"{$configured}\".");
            }

            return $configured;
        }

        return self::findOnPath($configured)
            ?? throw new AnalyserUnavailableException("The {$tool} binary \"{$configured}\" was not found on PATH. Install it (see README) or set SENTINEL_".strtoupper($tool).'_BINARY to its full path.');
    }

    public static function findOnPath(string $name): ?string
    {
        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $pathext = strtolower((string) getenv('PATHEXT'));
            $extensions = array_merge($extensions, explode(';', $pathext !== '' ? $pathext : '.exe;.bat;.cmd'));
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/'.chr(92)).DIRECTORY_SEPARATOR.$name.$extension;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
