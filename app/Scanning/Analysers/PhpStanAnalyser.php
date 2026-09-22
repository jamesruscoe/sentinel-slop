<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Plain PHPStan with the bundled neon: no bootstrap files, no Larastan, no
 * autoloader. cwd is the tool's work dir so nothing in the repo (phpstan.neon,
 * vendor/autoload.php, composer.json) is ever discovered.
 */
final class PhpStanAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'phpstan';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp();
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);

        $result = $this->execute([
            ...$this->tools->command('phpstan'),
            'analyse',
            '--configuration='.$this->options->configFile('phpstan.neon'),
            '--error-format=json',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
            '--memory-limit='.$this->options->phpstanMemoryLimit,
            $path,
        ], $workDir);

        $report = $this->decodeJson($result->stdout, 'JSON output'.($result->exitCode > 1 ? " (exit {$result->exitCode}, stderr: ".substr($result->stderr, 0, 200).')' : ''));
        $findings = new FindingCollection;

        foreach ((array) ($report['files'] ?? []) as $file => $entry) {
            foreach ((array) ($entry['messages'] ?? []) as $message) {
                [$category, $severity] = CategoryMapper::phpstan($message['identifier'] ?? null);
                $line = isset($message['line']) ? (int) $message['line'] : null;
                $relative = ltrim(str_replace(str_replace(chr(92), '/', $path), '', str_replace(chr(92), '/', (string) $file)), '/');

                $findings->add(new Finding('phpstan', $message['identifier'] ?? null, $category, $severity, $relative, $line,
                    (string) ($message['message'] ?? ''), $this->snippetFrom($path, $relative, $line)));
            }
        }

        foreach ((array) ($report['errors'] ?? []) as $error) {
            $findings->add(new Finding('phpstan', 'internal', FindingCategory::Other, Severity::Info, '', null, 'PHPStan: '.(string) $error));
        }

        return $findings;
    }
}
