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
            $line = isset($leak['StartLine']) ? (int) $leak['StartLine'] : null;

            // A credential-shaped string in documentation, a test or a fixture is almost always an example
            // (`curl -H "Authorization: Bearer your-api-key"` in api.md). It is shown, but it is not a critical
            // secret that caps the score; the message asks for confirmation instead of asserting a leak.
            if (self::isExampleLocation($relative)) {
                $findings->add(new Finding('gitleaks', $rule, FindingCategory::Placeholder, Severity::Low, $relative, $line,
                    sprintf('Credential-shaped value in documentation, a test or a fixture: %s (rule %s). Confirm it is an example, not a real secret.', (string) ($leak['Description'] ?? 'credential'), $rule)));

                continue;
            }

            // A key the code itself labels as public or client-side (an Algolia search key, a NEXT_PUBLIC_ or VITE_
            // value, a reCAPTCHA site key, an OAuth client id) is published by design. Shown, never score-capping.
            if ($line !== null && self::looksPublic($path, $relative, $line)) {
                $findings->add(new Finding('gitleaks', $rule, FindingCategory::Placeholder, Severity::Low, $relative, $line,
                    sprintf('Credential-shaped value that the surrounding code labels as public or client-side: %s (rule %s). Confirm it is a publishable key; if it is not, rotate it and move it to configuration.', (string) ($leak['Description'] ?? 'credential'), $rule)));

                continue;
            }

            $findings->add(new Finding('gitleaks', $rule, FindingCategory::Secrets, Severity::Critical, $relative, $line,
                sprintf('Possible secret: %s (rule %s). Rotate it and move it to configuration.', (string) ($leak['Description'] ?? 'credential'), $rule)));
        }

        return $findings;
    }

    /**
     * The flagged line, or the four lines above it, name the value as public.
     */
    private static function looksPublic(string $repoPath, string $relative, int $line): bool
    {
        $contents = @file($repoPath.'/'.$relative);
        if ($contents === false) {
            return false;
        }
        $window = implode("\n", array_slice($contents, max(0, $line - 5), 5));

        return preg_match('/algolia|public|search[_ -]?(?:only[_ -]?)?(?:api[_ -]?)?key|next_public_|vite_|nuxt_public_|react_app_|site[_ -]?key|client[_ -]?id|anon[_ -]?key|publishable|pk_(?:live|test)_|measurement[_ -]?id|recaptcha|turnstile|stripe[_ -]?public/i', $window) === 1;
    }

    public static function isExampleLocation(string $relativePath): bool
    {
        $lower = strtolower($relativePath);

        return preg_match('~\.(md|rst|txt|adoc)$~', $lower) === 1
            || preg_match('~(^|/)(docs?|documentation|examples?|samples?|fixtures?|tests?|__tests__|spec|specs|e2e|cypress|playwright|__mocks__|snapshots?|migrations|templates?|stubs?|scaffolds?|boilerplate)/~', $lower) === 1
            || preg_match('~(^|/)test_[^/]+\.py$|[._-](test|spec)\.[a-z]+$|test\.php$~', $lower) === 1;
    }
}
