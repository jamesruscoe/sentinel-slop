<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Exceptions\AnalyserFailedException;

/**
 * Laravel Pint in --test mode with the bundled pint.json. Never modifies files.
 */
final class PintAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'pint';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPhp();
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);

        $result = $this->execute([
            ...$this->tools->command('pint'),
            '--test',
            '--config='.$this->options->configFile('pint.json'),
            '--format=json',
            '--cache-file='.$workDir.'/cache.json',
            '--no-interaction',
            '--no-ansi',
            $path,
        ], $workDir);

        if ($result->exitCode > 1) {
            throw new AnalyserFailedException("pint exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 300));
        }

        $report = $this->decodeJson($result->stdout === '' ? '{"files":[]}' : $result->stdout, 'JSON output');
        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';

        foreach ((array) ($report['files'] ?? []) as $file) {
            $name = str_replace(chr(92), '/', (string) ($file['name'] ?? ''));
            $relative = str_starts_with($name, $root) ? substr($name, strlen($root)) : ltrim($name, '/');
            $fixers = array_values(array_map('strval', (array) ($file['appliedFixers'] ?? $file['fixers'] ?? [])));

            $findings->add(new Finding('pint', 'style', FindingCategory::Style, Severity::Low, $relative, null,
                $fixers === [] ? 'Code style differs from the Laravel preset.' : 'Code style differs from the Laravel preset: '.implode(', ', array_slice($fixers, 0, 8)).(count($fixers) > 8 ? ', …' : '')));
        }

        return $findings;
    }
}
