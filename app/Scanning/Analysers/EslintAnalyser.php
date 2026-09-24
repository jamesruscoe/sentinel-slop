<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Exceptions\AnalyserFailedException;

/**
 * ESLint with the bundled flat config only (--no-config-lookup), inline
 * directives disabled and no type-aware rules. cwd is the workspace root so
 * the repo is inside ESLint's base path; only `repo/` is passed as a target.
 */
final class EslintAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'eslint';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasJavaScript();
    }

    public function run(string $path): FindingCollection
    {
        $this->workDir($path);
        $root = $this->workspaceRoot($path);

        $result = $this->execute([
            ...$this->tools->command('eslint'),
            '--no-config-lookup',
            '--config', $this->options->configFile('eslint.config.mjs'),
            '--no-inline-config',
            '--no-error-on-unmatched-pattern',
            '--format', 'json',
            basename(rtrim(str_replace(chr(92), '/', $path), '/')),
        ], $root);

        if ($result->exitCode > 1 || ! str_starts_with(ltrim($result->stdout), '[')) {
            throw new AnalyserFailedException("eslint exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $findings = new FindingCollection;
        $repoRoot = rtrim(str_replace(chr(92), '/', $path), '/').'/';

        // ESLint's JSON lists every file it linted, with or without messages.
        $report = $this->decodeJson($result->stdout, 'JSON output');
        // The extensions the bundled config's `files` pattern covers (.vue is not linted).
        $this->assertCoverage($path, ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'mts', 'cts'], count($report), '0 files linted');

        foreach ($report as $file) {
            $name = str_replace(chr(92), '/', (string) ($file['filePath'] ?? ''));
            $relative = str_starts_with($name, $repoRoot) ? substr($name, strlen($repoRoot)) : $name;

            foreach ((array) ($file['messages'] ?? []) as $message) {
                $fatal = (bool) ($message['fatal'] ?? false);
                [$category, $severity] = CategoryMapper::eslint($message['ruleId'] ?? null, (int) ($message['severity'] ?? 1), $fatal);
                $line = isset($message['line']) ? (int) $message['line'] : null;

                $findings->add(new Finding('eslint', $message['ruleId'] ?? ($fatal ? 'parse-error' : null), $category, $severity, $relative, $line,
                    (string) ($message['message'] ?? ''), $this->snippetFrom($path, $relative, $line)));
            }
        }

        return $findings;
    }
}
