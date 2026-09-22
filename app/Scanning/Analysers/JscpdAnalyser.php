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
 * jscpd copy/paste detection with the bundled config (repo .jscpd.json,
 * package.json "jscpd" and .gitignore are never consulted).
 */
final class JscpdAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'jscpd';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);

        $result = $this->execute([
            ...$this->tools->command('jscpd'),
            '--config', $this->options->configFile('jscpd.json'),
            '--output', $workDir,
            '--reporters', 'json',
            '--silent',
            $path,
        ], $workDir);

        if ($result->exitCode !== 0) {
            throw new AnalyserFailedException("jscpd exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $report = $this->readJsonFile($workDir.'/jscpd-report.json', 'its report');
        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';
        // jscpd reports Windows extended-length paths (the //?/ prefix once slashes are normalised); drop it before relativising.
        $relative = function (string $name) use ($root): string {
            $name = str_replace(chr(92), '/', $name);
            if (str_starts_with($name, '//?/')) {
                $name = substr($name, 4);
            }

            return str_starts_with($name, $root) ? substr($name, strlen($root)) : $name;
        };

        foreach ((array) ($report['duplicates'] ?? []) as $duplicate) {
            $first = (array) ($duplicate['firstFile'] ?? []);
            $second = (array) ($duplicate['secondFile'] ?? []);
            $lines = (int) ($duplicate['lines'] ?? 0);
            $line = isset($second['start']) ? (int) $second['start'] : null;
            $file = $relative((string) ($second['name'] ?? ''));

            $findings->add(new Finding('jscpd', 'duplicate-block', FindingCategory::Duplication, $lines >= 30 ? Severity::Medium : Severity::Low, $file, $line,
                sprintf('%d duplicated lines also found in %s:%d.', $lines, $relative((string) ($first['name'] ?? '')), (int) ($first['start'] ?? 0)),
                isset($duplicate['fragment']) ? implode("\n", array_slice(preg_split('/\r\n|\n/', (string) $duplicate['fragment']) ?: [], 0, 6)) : null));
        }

        return $findings;
    }
}
