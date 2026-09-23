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
 * autoloader. The phar runs from a copy outside Sentinel Slop's vendor tree
 * (see ToolLocator) so PHPStan cannot find OUR autoloader either: user code is
 * never judged against our framework versions. cwd is the tool's work dir so
 * nothing in the repo (phpstan.neon, vendor/autoload.php, composer.json) is
 * ever discovered.
 *
 * Because no vendor code is visible, PHPStan cannot know anything about a
 * dependency's types. Errors that only exist for that reason are dropped
 * here rather than shown as the user's problem:
 *   - non-ignorable "extends/implements/uses unknown X" when X is provided
 *     by a dependency (composer.lock, or a declared vendor without a lock);
 *   - anything else whose message names such a provided-but-invisible type
 *     (argument/return mismatches against it, @throws "not Throwable", ...);
 *   - follow-on errors about the user's own classes whose parent is invisible
 *     (parent:: "does not extend any class", "does not have a constructor").
 * A genuinely unknown symbol, one no dependency provides, keeps every error.
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
        $index = DependencyIndex::build($path);
        $root = str_replace(chr(92), '/', $path);

        /** @var list<array{file: string, line: int|null, identifier: string, message: string}> $entries */
        $entries = [];
        foreach ((array) ($report['files'] ?? []) as $file => $entry) {
            foreach ((array) ($entry['messages'] ?? []) as $message) {
                $entries[] = [
                    'file' => ltrim(str_replace($root, '', str_replace(chr(92), '/', (string) $file)), '/'),
                    'line' => isset($message['line']) ? (int) $message['line'] : null,
                    'identifier' => (string) ($message['identifier'] ?? ''),
                    'message' => (string) ($message['message'] ?? ''),
                ];
            }
        }

        // Pass 1: user classes whose parent/interface/trait is provided by a dependency PHPStan cannot see.
        /** @var array<string, true> $invisibleParent  user class FQCN (lower) => true */
        $invisibleParent = [];
        foreach ($entries as $entry) {
            if (self::isUnknownSymbolError($entry['identifier']) && preg_match('/^Class (\S+) (?:extends unknown class|implements unknown interface|uses unknown trait) (\S+?)\.?$/', $entry['message'], $m) === 1 && $index->probablyProvided($m[2])) {
                $invisibleParent[strtolower($m[1])] = true;
            }
        }

        $findings = new FindingCollection;

        // Pass 2: keep only what PHPStan could actually judge.
        foreach ($entries as $entry) {
            if (self::judgesInvisibleType($entry['message'], $index) || self::followsFromInvisibleParent($entry['identifier'], $entry['message'], $invisibleParent)) {
                continue;
            }

            [$category, $severity] = CategoryMapper::phpstan($entry['identifier'] !== '' ? $entry['identifier'] : null);

            $findings->add(new Finding('phpstan', $entry['identifier'] !== '' ? $entry['identifier'] : null, $category, $severity, $entry['file'], $entry['line'],
                $entry['message'], $this->snippetFrom($path, $entry['file'], $entry['line'])));
        }

        foreach ((array) ($report['errors'] ?? []) as $error) {
            // PHPStan complaining that our neon tried to ignore a non-ignorable error is about our config,
            // not the user's code (and the text names the vendor type); the error itself was handled above.
            if (str_contains((string) $error, 'cannot be ignored, use excludePaths instead')) {
                continue;
            }

            $findings->add(new Finding('phpstan', 'internal', FindingCategory::Other, Severity::Info, '', null, 'PHPStan: '.(string) $error));
        }

        return $findings;
    }

    private static function isUnknownSymbolError(string $identifier): bool
    {
        return in_array($identifier, ['class.notFound', 'interface.notFound', 'trait.notFound', 'enum.notFound'], true);
    }

    /**
     * The message names a fully qualified type that a dependency provides but
     * PHPStan cannot see, so whatever it concluded about that type is unfounded.
     */
    public static function judgesInvisibleType(string $message, DependencyIndex $index): bool
    {
        foreach (self::qualifiedNames($message) as $fqcn) {
            if ($index->probablyProvided($fqcn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $invisibleParent
     */
    public static function followsFromInvisibleParent(string $identifier, string $message, array $invisibleParent): bool
    {
        if ($invisibleParent === [] || ! in_array($identifier, ['class.noParent', 'new.noConstructor', 'method.notFound', 'staticMethod.notFound', 'property.notFound', 'argument.count', 'arguments.count'], true)) {
            return false;
        }

        foreach (self::qualifiedNames($message) as $fqcn) {
            if (isset($invisibleParent[strtolower($fqcn)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function qualifiedNames(string $message): array
    {
        // Two backslash characters in the pattern = one escaped backslash for PCRE.
        if (preg_match_all('/[A-Z][A-Za-z0-9_]*(?:'.chr(92).chr(92).'[A-Z][A-Za-z0-9_]*)+/', $message, $m) === 0) {
            return [];
        }

        return $m[0];
    }
}
