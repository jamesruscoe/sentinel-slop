<?php

namespace App\Console\Commands;

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Process\ToolLocator;
use Composer\InstalledVersions;
use Illuminate\Console\Command;

/**
 * Checks every analyser binary and its version. Without --strict a missing
 * tool fails and a version off its pin is reported; with --strict (the Docker
 * build) any missing tool, missing pin, version mismatch, missing PHP extension
 * or too-small memory_limit fails, so drift is a red build and never a scan
 * with an unverified tool.
 */
class DoctorCommand extends Command
{
    protected $signature = 'sentinel:doctor {--strict : Fail on any missing tool, unpinned or mismatched version, missing PHP extension or small memory_limit (build-time check)}';

    protected $description = 'Check that every analyser binary is installed and matches its pinned version';

    public function handle(ToolLocator $tools, ProcessRunner $runner): int
    {
        $strict = (bool) $this->option('strict');
        $failures = [];
        $rows = [];

        foreach ($tools->report() as $tool => $status) {
            if (! $status['available']) {
                $failures[] = "{$tool} is missing";
                $rows[] = [$tool, 'MISSING', $status['error']];

                continue;
            }

            $version = $this->version($runner, $tool, $status['command'] ?? []);
            $expected = $this->expectedVersion($tool);

            if ($expected === null) {
                $rows[] = [$tool, $strict ? 'UNPINNED' : 'ok', "{$version} (no pinned version)"];
                if ($strict) {
                    $failures[] = "{$tool} has no pinned version";
                }

                continue;
            }

            if (! str_contains($version, $expected)) {
                $rows[] = [$tool, 'VERSION', "{$version}, pinned {$expected}; re-run the canary tests before trusting it"];
                if ($strict) {
                    $failures[] = "{$tool} is {$version}, pinned {$expected}";
                }

                continue;
            }

            $rows[] = [$tool, 'ok', $version];
        }

        foreach ($this->phpChecks() as [$name, $ok, $detail]) {
            $rows[] = [$name, $ok ? 'ok' : ($strict ? 'FAIL' : 'WARN'), $detail];
            if (! $ok && $strict) {
                $failures[] = "{$name}: {$detail}";
            }
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);
        $this->line('Scan storage: '.config('sentinel.scan_storage_path'));

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The version each tool must report: system binaries from config, Composer
     * and npm tools from what the lockfiles installed.
     */
    private function expectedVersion(string $tool): ?string
    {
        $pinned = match ($tool) {
            'semgrep' => (string) config('sentinel.tools.semgrep_version', ''),
            'gitleaks' => (string) config('sentinel.tools.gitleaks_version', ''),
            'ruff' => (string) config('sentinel.tools.ruff_version', ''),
            'phpstan' => self::composerVersion('phpstan/phpstan'),
            'pint' => self::composerVersion('laravel/pint'),
            'eslint' => self::npmVersion('eslint'),
            'jscpd' => self::npmVersion('jscpd'),
            default => '',
        };

        return $pinned === '' ? null : $pinned;
    }

    private static function composerVersion(string $package): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return '';
        }

        return ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
    }

    private static function npmVersion(string $package): string
    {
        $manifest = base_path("node_modules/{$package}/package.json");
        if (! is_file($manifest)) {
            return '';
        }
        $data = json_decode((string) file_get_contents($manifest), true);

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function phpChecks(): array
    {
        $checks = [];

        foreach ((array) config('sentinel.tools.required_extensions', []) as $extension) {
            $extension = (string) $extension;
            $checks[] = ["ext-{$extension}", extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'not loaded'];
        }

        $limit = (string) ini_get('memory_limit');
        $required = (string) config('sentinel.tools.worker_memory_limit', '1G');
        $ok = self::bytes($limit) === -1 || self::bytes($limit) >= self::bytes($required);
        $checks[] = ['php memory_limit', $ok, $ok ? $limit : "{$limit}, the worker needs at least {$required} (laravel/framework peaks around 150 MB; more files, more memory)"];

        return $checks;
    }

    private static function bytes(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '-1') {
            return -1;
        }
        $unit = strtolower(substr($ini, -1));
        $value = (int) $ini;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * @param  list<string>  $command
     */
    private function version(ProcessRunner $runner, string $tool, array $command): string
    {
        $flag = $tool === 'gitleaks' ? 'version' : '--version';
        $result = $runner->run(new ProcessSpec([...$command, $flag], base_path(), 60));
        $output = (string) preg_replace('/\e\[[0-9;]*m/', '', $result->stdout.$result->stderr);
        $line = trim((string) strtok(trim($output), "\n"));

        return $line === '' ? "exit {$result->exitCode}" : substr($line, 0, 80);
    }
}
