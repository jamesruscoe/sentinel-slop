<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Exceptions\AnalyserFailedException;
use App\Scanning\Process\ToolLocator;

/**
 * Semgrep with one bundled rules directory (malware, quality or slop).
 * Metrics off, no registry, no .semgrepignore, nosemgrep comments ignored.
 */
final class SemgrepAnalyser extends ProcessAnalyser
{
    public function __construct(
        ProcessRunner $runner,
        ToolLocator $tools,
        AnalyserOptions $options,
        private readonly string $rulesDirectory,
        private readonly string $analyserName = 'semgrep',
    ) {
        parent::__construct($runner, $tools, $options);
    }

    public function name(): string
    {
        return $this->analyserName;
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);
        $output = $workDir.'/semgrep.json';

        $result = $this->execute([
            ...$this->tools->command('semgrep'),
            'scan',
            '--config', $this->options->semgrepRulesPath.'/'.$this->rulesDirectory,
            '--json',
            '--output', $output,
            '--metrics=off',
            '--disable-version-check',
            '--no-git-ignore',
            '--disable-nosem',
            '--x-ignore-semgrepignore-files',
            '--quiet',
            '--timeout', '30',
            '--jobs', '2',
            $path,
        ], $workDir);

        if (! is_file($output)) {
            throw new AnalyserFailedException("semgrep exited with {$result->exitCode} without a report: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $report = $this->readJsonFile($output, 'its report');
        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';

        foreach ((array) ($report['results'] ?? []) as $hit) {
            $extra = (array) ($hit['extra'] ?? []);
            [$category, $severity] = CategoryMapper::semgrep((string) ($extra['severity'] ?? 'INFO'), (array) ($extra['metadata'] ?? []));
            $name = str_replace(chr(92), '/', (string) ($hit['path'] ?? ''));
            $relative = str_starts_with($name, $root) ? substr($name, strlen($root)) : ltrim($name, '/');
            $line = isset($hit['start']['line']) ? (int) $hit['start']['line'] : null;

            // Semgrep prefixes ids with the rules file path (resources.semgrep.malware.sentinel...); keep our id only.
            $checkId = (string) ($hit['check_id'] ?? '');
            $checkId = ((int) preg_match('/(sentinel[.].*)$/', $checkId, $m) === 1) ? $m[1] : $checkId;

            $findings->add(new Finding($this->name(), $checkId, $category, $severity, $relative, $line,
                trim((string) ($extra['message'] ?? '')), isset($extra['lines']) ? (string) $extra['lines'] : $this->snippetFrom($path, $relative, $line)));
        }

        return $findings;
    }
}
