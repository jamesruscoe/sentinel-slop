<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Synthesis\RulesetLoader;
use App\Scanning\Synthesis\SynthesisPayloadBuilder;

function payloadFinding(string $severity, string $file, ?string $snippet = null, string $message = 'msg'): Finding
{
    return Finding::fromArray(['tool' => 'phpstan', 'rule_id' => 'r', 'category' => 'type_safety', 'severity' => $severity, 'file_path' => $file, 'line' => 3, 'message' => $message, 'snippet' => $snippet]);
}

test('the payload lists findings most severe first and reports category counts', function () {
    $builder = new SynthesisPayloadBuilder(tokenBudget: 24000, maxFindings: 150, maxSnippetLines: 2);
    $result = $builder->build(new FindingCollection([
        payloadFinding('low', 'c.php'),
        payloadFinding('critical', 'a.php', "line1\nline2\nline3"),
        payloadFinding('medium', 'b.php'),
    ]));

    expect($result['included'])->toBe(3)->and($result['omitted'])->toBe(0)->and($result['total'])->toBe(3)->and($result['aggregated'])->toBe(0)
        ->and($result['text'])->toStartWith('- [critical] a.php:3 (phpstan/r) type_safety: msg')
        ->and($result['text'])->toContain("  line1\n  line2\n  ```")->not->toContain('line3')
        ->and($result['by_category'])->toBe(['type_safety' => 3]);
});

test('findings are trimmed to the token budget and the max count, keeping the most severe', function () {
    $findings = new FindingCollection([
        ...array_map(fn () => payloadFinding('low', 'low.php', str_repeat('some words ', 40)), range(1, 20)),
        payloadFinding('high', 'high.php'),
    ]);

    $budgeted = (new SynthesisPayloadBuilder(tokenBudget: 800, maxFindings: 150, aggregateThreshold: 1000))->build($findings, maxTokens: 700);
    $counted = (new SynthesisPayloadBuilder(tokenBudget: 100000, maxFindings: 5, aggregateThreshold: 1000))->build($findings);

    expect($budgeted['text'])->toStartWith('- [high] high.php')
        ->and($budgeted['included'])->toBeLessThan(21)
        ->and($budgeted['estimated_tokens'])->toBeLessThanOrEqual(700)
        ->and($counted['included'])->toBe(5)
        ->and($counted['omitted'])->toBe(16);
});

test('secret-looking values are scrubbed from messages and snippets before they reach the payload', function () {
    $result = (new SynthesisPayloadBuilder)->build(new FindingCollection([
        payloadFinding('medium', 'a.php', 'token = "Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A"', 'Found key Qm7xLp2ZtR9vHs4KwN8bYc3JfD6gTe1A here'),
    ]));

    expect($result['text'])->not->toContain('Qm7xLp2Z')->toContain('[REDACTED]');
});

test('rulesets are chosen from the stack', function () {
    $loader = new RulesetLoader(dirname(__DIR__, 3).'/resources/rulesets');

    expect($loader->namesFor(new Stack(['PHP' => 1], ['laravel', 'livewire'])))->toBe(['php', 'laravel'])
        ->and($loader->namesFor(new Stack(['TypeScript' => 1], ['next', 'react'])))->toBe(['javascript', 'typescript', 'react'])
        ->and($loader->namesFor(new Stack(['JavaScript' => 1], ['express'])))->toBe(['javascript'])
        ->and($loader->namesFor(new Stack(['Python' => 1], ['django'])))->toBe([])
        ->and($loader->load(new Stack(['PHP' => 1]))['php'])->toContain('strict_types');
});
