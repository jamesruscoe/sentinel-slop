<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Normalise\SecretRedactor;

/**
 * Turns findings into the compact text the LLM sees, most severe first,
 * trimmed to a token budget. A rule that fires more than the aggregation
 * threshold becomes ONE line with a count and example paths, so the budget
 * goes to findings that differ from each other. Snippets are scrubbed again
 * here so nothing token-shaped ever leaves the process.
 */
final class SynthesisPayloadBuilder
{
    private const EXAMPLE_PATHS = 5;

    public function __construct(
        private readonly int $tokenBudget = 24000,
        private readonly int $maxFindings = 150,
        private readonly int $maxSnippetLines = 6,
        private readonly int $aggregateThreshold = 5,
    ) {}

    /**
     * @param  int|null  $maxTokens  A hard ceiling from the caller (what the context window leaves after the
     *                               system prompt and the reply allowance); the configured budget still applies.
     * @return array{text: string, included: int, omitted: int, aggregated: int, total: int, estimated_tokens: int, by_category: array<string, int>}
     */
    public function build(FindingCollection $findings, ?int $maxTokens = null): array
    {
        $budget = max(500, min($this->tokenBudget, $maxTokens ?? $this->tokenBudget));

        $byCategory = [];
        foreach ($findings as $finding) {
            $byCategory[$finding->category->value] = ($byCategory[$finding->category->value] ?? 0) + 1;
        }
        arsort($byCategory);

        $entries = $this->aggregate($findings);
        usort($entries, fn (array $a, array $b) => [$b['rank'], $b['count']] <=> [$a['rank'], $a['count']]);

        $lines = [];
        $tokens = 0;
        $included = 0;
        $coveredFindings = 0;

        foreach ($entries as $entry) {
            if ($included >= $this->maxFindings) {
                break;
            }

            $cost = self::estimateTokens($entry['text']);
            if ($tokens + $cost > $budget) {
                break;
            }

            $lines[] = $entry['text'];
            $tokens += $cost;
            $included++;
            $coveredFindings += $entry['count'];
        }

        return [
            'text' => implode("\n", $lines),
            'included' => $included,
            'omitted' => $findings->count() - $coveredFindings,
            'aggregated' => count(array_filter($entries, fn (array $e) => $e['count'] > 1)),
            'total' => $findings->count(),
            'estimated_tokens' => $tokens,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Conservative: real tokenisers average 3.5-4 characters per token on
     * code and paths, so 3 keeps the estimate on the safe side.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 3);
    }

    /**
     * One entry per finding, except rules over the threshold which collapse
     * into a single entry carrying the count and a few example locations.
     *
     * @return list<array{text: string, rank: int, count: int}>
     */
    private function aggregate(FindingCollection $findings): array
    {
        /** @var array<string, list<Finding>> $groups */
        $groups = [];
        foreach ($findings as $finding) {
            $groups[$finding->tool.'|'.($finding->ruleId ?? '').'|'.$finding->severity->value][] = $finding;
        }

        $entries = [];
        foreach ($groups as $group) {
            if (count($group) <= $this->aggregateThreshold) {
                foreach ($group as $finding) {
                    $entries[] = ['text' => $this->format($finding), 'rank' => $finding->severity->rank(), 'count' => 1];
                }

                continue;
            }

            $first = $group[0];
            $paths = array_values(array_unique(array_map(fn (Finding $f) => $f->filePath, $group)));
            $messages = array_values(array_unique(array_map(fn (Finding $f) => $f->message, $group)));
            $more = count($group) - self::EXAMPLE_PATHS;

            // Identical messages (Pint, narrating comments): one message plus example locations.
            // Differing messages (one rule, many subjects): each example keeps its own message so
            // the model never attributes one instance's subject to the others.
            $examples = count($messages) === 1
                ? implode('; ', array_map(fn (Finding $f) => $f->location(), array_slice($group, 0, self::EXAMPLE_PATHS)))
                : implode('; ', array_map(fn (Finding $f) => $f->location().' ('.self::shorten(SecretRedactor::scrub($f->message)).')', array_slice($group, 0, self::EXAMPLE_PATHS)));

            $entries[] = [
                'text' => sprintf('- [%s] %d findings in %d files (%s%s) %s: %s%s%s. Fix the pattern everywhere it occurs, not just the examples.',
                    $first->severity->value,
                    count($group),
                    count($paths),
                    $first->tool,
                    $first->ruleId !== null ? '/'.$first->ruleId : '',
                    $first->category->value,
                    count($messages) === 1 ? SecretRedactor::scrub($first->message).' Examples: ' : 'Instances: ',
                    $examples,
                    $more > 0 ? " (+{$more} more)" : '',
                ),
                'rank' => $first->severity->rank(),
                'count' => count($group),
            ];
        }

        return $entries;
    }

    private static function shorten(string $message): string
    {
        return mb_strlen($message) > 160 ? mb_substr($message, 0, 159).'…' : $message;
    }

    private function format(Finding $finding): string
    {
        $line = sprintf('- [%s] %s (%s%s) %s: %s',
            $finding->severity->value,
            $finding->location(),
            $finding->tool,
            $finding->ruleId !== null ? '/'.$finding->ruleId : '',
            $finding->category->value,
            SecretRedactor::scrub($finding->message),
        );

        if ($finding->snippet !== null && $finding->snippet !== '') {
            $snippet = array_slice(preg_split('/\r?\n/', $finding->snippet) ?: [], 0, $this->maxSnippetLines);
            $line .= "\n  ```\n  ".implode("\n  ", array_map(fn (string $l) => SecretRedactor::scrub($l), $snippet))."\n  ```";
        }

        return $line;
    }
}
