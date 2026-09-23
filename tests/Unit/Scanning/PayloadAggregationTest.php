<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Synthesis\SynthesisPayloadBuilder;

function aggFinding(string $tool, string $rule, string $severity, string $file, string $message = 'msg'): Finding
{
    return Finding::fromArray(['tool' => $tool, 'rule_id' => $rule, 'category' => 'style', 'severity' => $severity, 'file_path' => $file, 'line' => 1, 'message' => $message]);
}

test('a rule firing more than the threshold collapses into one line with a count and examples, before trimming', function () {
    $findings = new FindingCollection([
        ...array_map(fn (int $i) => aggFinding('pint', 'style', 'low', "app/F{$i}.php", 'Code style differs from the Laravel preset.'), range(1, 131)),
        ...array_map(fn (int $i) => aggFinding('phpstan', 'return.type', 'medium', "app/R{$i}.php", 'Bad return'), range(1, 3)),
        aggFinding('swallowed-exceptions', 'empty-catch', 'medium', 'app/S.php', 'Empty catch'),
    ]);

    $result = (new SynthesisPayloadBuilder(tokenBudget: 100000, maxFindings: 150, aggregateThreshold: 5))->build($findings);
    $lines = explode("\n", $result['text']);

    expect($result['total'])->toBe(135)
        ->and($result['included'])->toBe(5)
        ->and($result['omitted'])->toBe(0)
        ->and($result['aggregated'])->toBe(1)
        ->and($lines)->toHaveCount(5)
        ->and($lines[0])->toStartWith('- [medium]')
        ->and(end($lines))->toStartWith('- [low] 131 findings in 131 files (pint/style) style: Code style differs from the Laravel preset. Examples: app/F1.php:1; app/F2.php:1; app/F3.php:1; app/F4.php:1; app/F5.php:1 (+126 more).')
        ->and($result['text'])->not->toContain('app/F6.php');
});

test('aggregation frees the budget for findings that differ from each other', function () {
    $findings = new FindingCollection([
        ...array_map(fn (int $i) => aggFinding('pint', 'style', 'low', "app/F{$i}.php", 'Code style differs.'), range(1, 200)),
        ...array_map(fn (int $i) => aggFinding('phpstan', "rule{$i}", 'medium', "app/R{$i}.php", 'Distinct problem '.$i), range(1, 40)),
    ]);

    $tight = (new SynthesisPayloadBuilder(tokenBudget: 1500, maxFindings: 150, aggregateThreshold: 5))->build($findings);

    expect($tight['included'])->toBeGreaterThan(30)
        ->and(substr_count($tight['text'], 'Distinct problem'))->toBeGreaterThan(30)
        ->and($tight['text'])->toContain('200 findings in 200 files (pint/style)');
});

test('an aggregated rule whose messages differ keeps each example with its own message', function () {
    $findings = new FindingCollection(array_map(fn (int $i) => aggFinding('deps', 'transitive', 'low', "app/F{$i}.php", "Package {$i} is only transitive"), range(1, 7)));

    $text = (new SynthesisPayloadBuilder(aggregateThreshold: 5))->build($findings)['text'];

    expect($text)->toContain('7 findings in 7 files (deps/transitive) style: Instances: app/F1.php:1 (Package 1 is only transitive); app/F2.php:1 (Package 2 is only transitive)')
        ->and($text)->toContain('(+2 more)')
        ->and($text)->not->toContain('Examples:');
});

test('rules at or under the threshold stay itemised', function () {
    $findings = new FindingCollection(array_map(fn (int $i) => aggFinding('pint', 'style', 'low', "app/F{$i}.php"), range(1, 5)));

    $result = (new SynthesisPayloadBuilder(aggregateThreshold: 5))->build($findings);

    expect($result['included'])->toBe(5)->and($result['aggregated'])->toBe(0)->and($result['text'])->toContain('app/F5.php:1');
});
