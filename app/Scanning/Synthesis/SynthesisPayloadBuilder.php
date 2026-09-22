<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Normalise\SecretRedactor;

/**
 * Turns findings into the compact text the LLM sees, most severe first,
 * trimmed to a token budget. Snippets are scrubbed again here so nothing
 * token-shaped ever leaves the process, whichever tool produced the finding.
 */
final class SynthesisPayloadBuilder
{
    public function __construct(
        private readonly int $tokenBudget = 24000,
        private readonly int $maxFindings = 150,
        private readonly int $maxSnippetLines = 6,
    ) {}

    /**
     * @return array{text: string, included: int, omitted: int, estimated_tokens: int, by_category: array<string, int>}
     */
    public function build(FindingCollection $findings, int $reservedTokens = 0): array
    {
        $budget = max(500, $this->tokenBudget - $reservedTokens);
        $sorted = $findings->all();
        usort($sorted, fn (Finding $a, Finding $b) => $b->severity->rank() <=> $a->severity->rank());

        $byCategory = [];
        foreach ($sorted as $finding) {
            $byCategory[$finding->category->value] = ($byCategory[$finding->category->value] ?? 0) + 1;
        }
        arsort($byCategory);

        $lines = [];
        $tokens = 0;
        $included = 0;

        foreach ($sorted as $finding) {
            if ($included >= $this->maxFindings) {
                break;
            }

            $entry = $this->format($finding);
            $cost = self::estimateTokens($entry);
            if ($tokens + $cost > $budget) {
                break;
            }

            $lines[] = $entry;
            $tokens += $cost;
            $included++;
        }

        return [
            'text' => implode("\n", $lines),
            'included' => $included,
            'omitted' => count($sorted) - $included,
            'estimated_tokens' => $tokens,
            'by_category' => $byCategory,
        ];
    }

    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function format(Finding $finding): string
    {
        $line = sprintf('- [%s] %s%s (%s%s) %s: %s',
            $finding->severity->value,
            $finding->filePath,
            $finding->line !== null ? ':'.$finding->line : '',
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
