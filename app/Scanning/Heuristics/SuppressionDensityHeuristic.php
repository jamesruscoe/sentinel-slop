<?php

declare(strict_types=1);

namespace App\Scanning\Heuristics;

use App\Scanning\Contracts\Heuristic;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

/**
 * Inline suppression comments (eslint-disable, phpstan-ignore, phpcs:ignore,
 * nosemgrep, gitleaks:allow, @ts-ignore, ...). Each one is a Low finding and
 * the total per thousand lines is recorded on the scan as a slop signal.
 */
final class SuppressionDensityHeuristic implements Heuristic
{
    /** @var array<string, string>  kind => regex */
    private const PATTERNS = [
        'eslint' => '~eslint-disable(-next-line|-line)?\b~',
        'phpstan' => '~@phpstan-ignore(-next-line|-line)?\b~',
        'psalm' => '~@psalm-suppress\b~',
        'phpcs' => '~phpcs:(ignore|disable)\b~',
        'semgrep' => '~\bnosem(grep)?\b~',
        'gitleaks' => '~gitleaks:allow\b~',
        'typescript' => '~@ts-(ignore|nocheck|expect-error)\b~',
        'prettier' => '~prettier-ignore\b~',
        'pylint' => '~\bnoqa\b|pylint:\s*disable|type:\s*ignore~',
        'sonar' => '~NOSONAR\b~',
    ];

    private const MAX_PER_FILE = 20;

    public function name(): string
    {
        return 'suppression-density';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        return $this->analyse($path)['findings'];
    }

    /**
     * @return array{findings: FindingCollection, count: int, lines: int, density: float, by_kind: array<string, int>}
     */
    public function analyse(string $path): array
    {
        $findings = new FindingCollection;
        $count = 0;
        $lines = 0;
        $byKind = [];

        foreach (SourceFiles::in($path, [...SourceFiles::PHP, ...SourceFiles::JS, ...SourceFiles::PYTHON]) as $file) {
            $perFile = 0;

            foreach (SourceFiles::lines($file['absolute']) as $index => $line) {
                if (trim($line) !== '') {
                    $lines++;
                }

                $kind = self::kindOf($line);
                if ($kind === null) {
                    continue;
                }

                $count++;
                $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;

                if ($perFile++ < self::MAX_PER_FILE) {
                    $findings->add(new Finding($this->name(), 'inline-suppression', FindingCategory::Slop, Severity::Low, $file['path'], $index + 1,
                        "Inline {$kind} suppression; fix the underlying issue or justify the exception in a comment.", trim($line)));
                }
            }
        }

        arsort($byKind);

        return [
            'findings' => $findings,
            'count' => $count,
            'lines' => $lines,
            'density' => $lines === 0 ? 0.0 : round($count / $lines * 1000, 2),
            'by_kind' => $byKind,
        ];
    }

    private static function kindOf(string $line): ?string
    {
        if (preg_match('~(//|#|/\*|\*|<!--)~', $line) !== 1) {
            return null;
        }

        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return $kind;
            }
        }

        return null;
    }
}
