<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Score\LinesOfCodeCounter;
use App\Scanning\Score\ScoreResult;
use App\Scanning\Score\SlopScoreCalculator;

function scoreFinding(string $severity, string $category = 'style'): Finding
{
    return Finding::fromArray(['tool' => 't', 'rule_id' => 'r', 'category' => $category, 'severity' => $severity, 'file_path' => 'a.php', 'line' => 1, 'message' => 'm']);
}

function calculator(): SlopScoreCalculator
{
    return SlopScoreCalculator::fromConfig([
        'weights' => ['critical' => 25, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0],
        'curve' => ['scale' => 90, 'exponent' => 2.1],
        'critical_cap' => 40,
        'suppression_weight' => 2.0,
        'suppression_cap' => 20,
    ]);
}

function findingsOf(array $counts): FindingCollection
{
    $findings = [];
    foreach ($counts as $severity => $count) {
        for ($i = 0; $i < $count; $i++) {
            $findings[] = scoreFinding($severity);
        }
    }

    return new FindingCollection($findings);
}

test('a clean repository scores 100', function () {
    $result = calculator()->calculate(new FindingCollection, 1000);

    expect($result->score)->toBe(100)->and($result->penalty)->toBe(0)->and($result->criticalCapApplied)->toBeFalse();
});

test('the curve keeps light problems high and pushes dense problems below 30', function (array $counts, int $lines, int $expected) {
    expect(calculator()->calculate(findingsOf($counts), $lines)->score)->toBe($expected);
})->with([
    'two low findings in 2k lines' => [['low' => 2], 2000, 100],
    'a few mediums in 5k lines' => [['medium' => 3, 'low' => 4], 5000, 100],
    'one high and five mediums in 3k lines' => [['high' => 1, 'medium' => 5], 3000, 99],
    '800 lines with 10 medium findings' => [['medium' => 10], 800, 75],
    '800 lines with 10 medium and 5 low' => [['medium' => 10, 'low' => 5], 800, 69],
    'sloppy-laravel shape: 1 critical, 2 high, 9 medium, 11 low in 878 lines' => [['critical' => 1, 'high' => 2, 'medium' => 9, 'low' => 11], 878, 25],
    'dense: 20 high in 500 lines' => [['high' => 20], 500, 0],
]);

test('the score is monotonic in density', function () {
    $previous = 101;
    foreach (range(0, 200, 5) as $density) {
        $score = calculator()->curve((float) $density);
        expect($score)->toBeLessThanOrEqual($previous);
        $previous = $score;
    }
});

test('suppression density costs points up to the cap', function () {
    expect(calculator()->calculate(new FindingCollection, 1000, 3.0)->score)->toBe(94)
        ->and(calculator()->calculate(new FindingCollection, 1000, 50.0)->score)->toBe(80)
        ->and(calculator()->calculate(new FindingCollection, 1000, 50.0)->suppressionPenalty)->toBe(20);
});

test('security-critical findings cap the score', function () {
    $result = calculator()->calculate(new FindingCollection([scoreFinding('critical', 'secrets')]), 100000);

    expect($result->score)->toBe(40)->and($result->criticalCapApplied)->toBeTrue();
});

test('the score never goes below zero and tiny repos are not divided by zero', function () {
    $findings = new FindingCollection(array_fill(0, 30, scoreFinding('high')));

    expect(calculator()->calculate($findings, 0)->score)->toBe(0)
        ->and(calculator()->calculate($findings, 10)->score)->toBe(0);
});

test('score results round-trip and config curve values are honoured', function () {
    $linear = SlopScoreCalculator::fromConfig(['weights' => ['high' => 50], 'curve' => ['scale' => 1000, 'exponent' => 1], 'critical_cap' => 10]);
    $result = $linear->calculate(new FindingCollection([scoreFinding('high')]), 1000);

    // density 50, scale 1000, exponent 1 => 100 * exp(-0.05) = 95
    expect($result->score)->toBe(95)
        ->and(ScoreResult::fromArray($result->toArray())->toArray())->toBe($result->toArray());
});

test('lines of code counts non-blank, non-comment lines of source files only', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/a.php', "<?php\n\n// comment\n/* block */\n\$a = 1;\n\$b = 2;\n");
    file_put_contents($workspace->repoPath().'/b.ts', "// header\nexport const x = 1\n\n");
    file_put_contents($workspace->repoPath().'/README.md', "lots\nof\nlines\n");

    expect(LinesOfCodeCounter::count($workspace->repoPath(), ['a.php', 'b.ts', 'README.md', 'missing.php']))->toBe(4)
        ->and(LinesOfCodeCounter::isCode('x.blade.php'))->toBeTrue()
        ->and(LinesOfCodeCounter::isCode('x.json'))->toBeFalse();
});
