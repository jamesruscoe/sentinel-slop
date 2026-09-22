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
 * gitleaks in directory mode with the bundled config and an empty bundled
 * .gitleaksignore, inline gitleaks:allow comments ignored, and secrets fully
 * redacted by the tool itself so they never reach our process.
 */
final class GitleaksAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'gitleaks';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);
        $report = $workDir.'/gitleaks.json';

        $result = $this->execute([
            ...$this->tools->command('gitleaks'),
            'dir', $path,
            '--config', $this->options->configFile('gitleaks.toml'),
            '--gitleaks-ignore-path', $this->options->configPath,
            '--ignore-gitleaks-allow',
            '--redact=100',
            '--no-banner',
            '--no-color',
            '--log-level', 'error',
            '--report-format', 'json',
            '--report-path', $report,
            '--exit-code', '0',
            '--max-target-megabytes', '5',
        ], $workDir);

        if ($result->exitCode !== 0) {
            throw new AnalyserFailedException("gitleaks exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';

        foreach ($this->readJsonFile($report, 'its report') as $leak) {
            $name = str_replace(chr(92), '/', (string) ($leak['File'] ?? ''));
            $relative = str_starts_with($name, $root) ? substr($name, strlen($root)) : ltrim($name, '/');
            $rule = (string) ($leak['RuleID'] ?? 'secret');

            $findings->add(new Finding('gitleaks', $rule, FindingCategory::Secrets, Severity::Critical, $relative,
                isset($leak['StartLine']) ? (int) $leak['StartLine'] : null,
                sprintf('Possible secret: %s (rule %s). Rotate it and move it to configuration.', (string) ($leak['Description'] ?? 'credential'), $rule)));
        }

        return $findings;
    }
}
