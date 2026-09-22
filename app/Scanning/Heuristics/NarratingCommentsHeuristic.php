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
 * Flags line comments that merely restate the code on the next line, e.g.
 * "// Get the order by id" above "return Order::find($id);".
 */
final class NarratingCommentsHeuristic implements Heuristic
{
    private const STOPWORDS = ['the', 'a', 'an', 'to', 'of', 'and', 'or', 'is', 'it', 'this', 'that', 'for', 'in', 'on', 'with', 'we', 'now', 'then', 'if', 'as', 'by', 'from', 'be', 'at', 'into', 'here', 'just', 'will', 'are', 'our', 'its', 'via', 'using'];

    private const SKIP_PREFIXES = ['todo', 'fixme', 'note', 'hack', 'xxx', 'eslint', 'prettier', 'phpcs', 'phpstan', 'psalm', 'istanbul', 'ts-', 'noinspection', '@', 'nosemgrep', 'gitleaks', 'c8', 'v8', 'webpack', 'vite', 'region', 'endregion', 'type:', '!'];

    private const MAX_PER_FILE = 5;

    public function __construct(private readonly float $minOverlap = 0.6) {}

    public function name(): string
    {
        return 'narrating-comments';
    }

    public function supports(Stack $stack): bool
    {
        return true;
    }

    public function run(string $path): FindingCollection
    {
        $findings = new FindingCollection;

        foreach (SourceFiles::in($path, [...SourceFiles::PHP, ...SourceFiles::JS, ...SourceFiles::PYTHON]) as $file) {
            $lines = SourceFiles::lines($file['absolute']);
            $reported = 0;

            foreach ($lines as $index => $line) {
                if ($reported >= self::MAX_PER_FILE) {
                    break;
                }

                $comment = self::commentText($line);
                $next = $comment === null ? null : self::nextCodeLine($lines, $index);
                if ($comment === null || $next === null) {
                    continue;
                }

                $words = self::commentWords($comment);
                if (count($words) < 2) {
                    continue;
                }

                $matched = array_intersect($words, self::codeTokens($next));
                if (count($matched) >= 2 && count($matched) / count($words) >= $this->minOverlap) {
                    $findings->add(new Finding($this->name(), 'narrating-comment', FindingCategory::Slop, Severity::Low, $file['path'], $index + 1,
                        'Comment restates the code beneath it; explain why, or delete it.', trim($line)."\n".$next));
                    $reported++;
                }
            }
        }

        return $findings;
    }

    private static function commentText(string $line): ?string
    {
        if (preg_match('~^\s*(//|#)\s*(.+)$~', $line, $m) !== 1 || str_starts_with(trim($line), '#!')) {
            return null;
        }

        $text = trim($m[2]);
        $lower = strtolower($text);

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return null;
            }
        }

        if (str_contains($text, '?') || preg_match('~\b(because|why|so that|workaround|otherwise|careful|must|should|avoid)\b~i', $text) === 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function nextCodeLine(array $lines, int $index): ?string
    {
        for ($i = $index + 1; $i < min(count($lines), $index + 3); $i++) {
            $candidate = trim($lines[$i]);
            if ($candidate === '' || preg_match('~^(//|#|/\*|\*)~', $candidate) === 1) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function commentWords(string $comment): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($comment)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w) => strlen($w) > 1 && ! in_array($w, self::STOPWORDS, true))));
    }

    /**
     * @return list<string>
     */
    private static function codeTokens(string $code): array
    {
        $tokens = [];

        foreach (preg_split('/[^A-Za-z0-9_]+/', $code) ?: [] as $identifier) {
            foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])|_+|(?<=[A-Z])(?=[A-Z][a-z])/', $identifier) ?: [] as $part) {
                if ($part !== '') {
                    $tokens[] = strtolower($part);
                }
            }
        }

        return array_values(array_unique($tokens));
    }
}
