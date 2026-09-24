<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Exceptions\AnalyserFailedException;

/**
 * Ruff for Python: pyflakes, bugbear, bandit, complexity and the rest of the
 * bundled rule selection. An explicit `--config <file>` disables Ruff's
 * configuration discovery, so the repository's pyproject.toml, ruff.toml,
 * .ruff.toml and setup.cfg are never read (`--isolated` cannot be combined
 * with a config file; the canary test proves the explicit file is enough),
 * and `--ignore-noqa` makes inline suppressions ineffective. Coverage is
 * checked through `--show-files`: a Python repository on which Ruff sees no
 * files is a failure, not a clean bill.
 */
final class RuffAnalyser extends ProcessAnalyser
{
    public function name(): string
    {
        return 'ruff';
    }

    public function supports(Stack $stack): bool
    {
        return $stack->hasPython();
    }

    public function run(string $path): FindingCollection
    {
        $workDir = $this->workDir($path);
        $config = $this->options->configFile('ruff.toml');
        $base = [...$this->tools->command('ruff'), 'check', '--config', $config, '--no-cache'];

        $listing = $this->execute([...$base, '--show-files', $path], $workDir);
        $seen = count(array_filter(preg_split('/\r?\n/', trim($listing->stdout)) ?: [], fn (string $l) => str_ends_with(trim($l), '.py')));
        $this->assertCoverage($path, ['py'], $seen, '0 Python files to check');

        $result = $this->execute([...$base, '--output-format', 'json', '--exit-zero', '--ignore-noqa', $path], $workDir);
        if ($result->exitCode !== 0 || ! str_starts_with(ltrim($result->stdout), '[')) {
            throw new AnalyserFailedException("ruff exited with {$result->exitCode}: ".substr($result->stderr.$result->stdout, 0, 400));
        }

        $findings = new FindingCollection;
        $root = rtrim(str_replace(chr(92), '/', $path), '/').'/';

        foreach ($this->decodeJson($result->stdout, 'JSON output') as $diagnostic) {
            $name = str_replace(chr(92), '/', (string) ($diagnostic['filename'] ?? ''));
            $relative = str_starts_with($name, $root) ? substr($name, strlen($root)) : ltrim($name, '/');
            $code = (string) ($diagnostic['code'] ?? '');
            $line = isset($diagnostic['location']['row']) ? (int) $diagnostic['location']['row'] : null;
            [$category, $severity] = CategoryMapper::ruff($code);

            $findings->add(new Finding('ruff', $code !== '' ? $code : null, $category, $severity, $relative, $line,
                trim((string) ($diagnostic['message'] ?? '')), $this->snippetFrom($path, $relative, $line)));
        }

        return $findings;
    }
}
