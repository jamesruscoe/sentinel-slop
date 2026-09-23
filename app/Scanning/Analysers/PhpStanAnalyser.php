<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Detect\DependencyIndex;
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
        $index = DependencyIndex::build($path);
        /** @var array<string, true> $unknownButInstalledParents  files whose parent class lives in an installed package */
        $unknownButInstalledParents = [];

        foreach ((array) ($report['files'] ?? []) as $file => $entry) {
            foreach ((array) ($entry['messages'] ?? []) as $message) {
                $identifier = (string) ($message['identifier'] ?? '');
                $text = (string) ($message['message'] ?? '');
                $line = isset($message['line']) ? (int) $message['line'] : null;
                $relative = ltrim(str_replace(str_replace(chr(92), '/', $path), '', str_replace(chr(92), '/', (string) $file)), '/');

                // "extends unknown class X" and friends cannot be ignored in the neon (PHPStan marks them
                // non-ignorable). They only appear because vendor is never installed, so drop them when X
                // resolves to a package in composer.lock; a genuinely unknown X stays.
                if (self::isUnknownSymbolError($identifier) && self::mentionsInstalledClass($text, $index)) {
                    if (str_contains($text, 'extends unknown class')) {
                        $unknownButInstalledParents[$relative] = true;
                    }

                    continue;
                }
                if ($identifier === 'class.noParent' && isset($unknownButInstalledParents[$relative])) {
                    continue;
                }

                [$category, $severity] = CategoryMapper::phpstan($identifier !== '' ? $identifier : null);

                $findings->add(new Finding('phpstan', $identifier !== '' ? $identifier : null, $category, $severity, $relative, $line,
                    $text, $this->snippetFrom($path, $relative, $line)));
            }
        }

        foreach ((array) ($report['errors'] ?? []) as $error) {
            $findings->add(new Finding('phpstan', 'internal', FindingCategory::Other, Severity::Info, '', null, 'PHPStan: '.(string) $error));
        }

        return $findings;
    }

    private static function isUnknownSymbolError(string $identifier): bool
    {
        return in_array($identifier, ['class.notFound', 'interface.notFound', 'trait.notFound', 'enum.notFound'], true);
    }

    private static function mentionsInstalledClass(string $message, DependencyIndex $index): bool
    {
        // Two backslash characters in the pattern = one escaped backslash for PCRE.
        if (preg_match_all('/[A-Z][A-Za-z0-9_]*(?:'.chr(92).chr(92).'[A-Z][A-Za-z0-9_]*)+/', $message, $m) === 0) {
            return false;
        }

        foreach ($m[0] as $fqcn) {
            if ($index->resolvePhp($fqcn) !== null) {
                return true;
            }
        }

        return false;
    }
}
