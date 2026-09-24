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
        'curve' => ['scale' => 65, 'exponent' => 1.4],
        'critical_cap' => 40,
        'low_points' => 0.1,
        'low_cap' => 5,
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

test('the score is driven by medium-and-above findings, with size giving a sqrt allowance', function (array $counts, int $lines, int $expected) {
    expect(calculator()->calculate(findingsOf($counts), $lines)->score)->toBe($expected);
})->with([
    'two low findings in 2k lines' => [['low' => 2], 2000, 100],
    'one medium in 5k lines' => [['medium' => 1], 5000, 99],
    'three mediums in 1k lines' => [['medium' => 3], 1000, 91],
    'one high and five mediums in 3k lines' => [['high' => 1, 'medium' => 5], 3000, 85],
    '800 lines with 10 medium findings' => [['medium' => 10], 800, 60],
    'dog-kennel shape: 49 medium, 181 low in 21.2k lines' => [['medium' => 49, 'low' => 181], 21233, 53],
    'the same 49 mediums in 800 lines' => [['medium' => 49], 800, 1],
    'sloppy-laravel shape without the secret: 2 high, 10 medium, 11 low in 878 lines' => [['high' => 2, 'medium' => 10, 'low' => 11], 878, 40],
    'dense: 20 high in 500 lines' => [['high' => 20], 500, 1],
]);

test('low findings cost a little and never more than the cap, however many there are', function () {
    $tenLows = calculator()->calculate(findingsOf(['low' => 10]), 1000)->score;
    $thousandLows = calculator()->calculate(findingsOf(['low' => 1000]), 1000);

    expect($tenLows)->toBe(99)
        ->and($thousandLows->score)->toBe(95)
        ->and($thousandLows->lowPenalty)->toBe(5)
        ->and($thousandLows->penalty)->toBe(0);
});

test('a large repository is not given a free pass: 49 mediums always hurt', function () {
    expect(calculator()->calculate(findingsOf(['medium' => 49]), 21233)->score)->toBeLessThan(65)
        ->and(calculator()->calculate(findingsOf(['medium' => 49]), 200000)->score)->toBeLessThan(90);
});

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

    // density 50 (sqrt(1 KLOC) = 1), scale 1000, exponent 1 => 100 * exp(-0.05) = 95
    expect($result->score)->toBe(95)
        ->and(ScoreResult::fromArray($result->toArray())->toArray())->toBe($result->toArray());
});

test('structure findings move the score but never by more than the cap', function () {
    $structure = fn (string $severity) => Finding::fromArray(['tool' => 'profile', 'rule_id' => 'no-tests-at-all', 'category' => 'structure', 'severity' => $severity, 'file_path' => '', 'line' => null, 'message' => 'm']);

    $one = calculator()->calculate(new FindingCollection([$structure('medium')]), 5000);
    $five = calculator()->calculate(new FindingCollection(array_fill(0, 5, $structure('high'))), 1000);
    $mixed = calculator()->calculate(new FindingCollection([...array_fill(0, 5, $structure('high')), ...array_fill(0, 10, scoreFinding('medium'))]), 800);

    expect($one->score)->toBe(99)->and($one->structurePenalty)->toBe(1)->and($one->scoreWithoutStructure)->toBe(100)->and($one->structureCount)->toBe(1)
        // Five High absence findings in 1k lines would take 100 down to 69 uncapped; the cap holds it at 15 points.
        ->and($five->scoreWithoutStructure)->toBe(100)->and($five->structurePenalty)->toBe(15)->and($five->score)->toBe(85)
        // Ordinary findings keep their full weight; only the structure part is capped.
        ->and($mixed->scoreWithoutStructure)->toBe(60)->and($mixed->score)->toBe(45)->and($mixed->penalty)->toBe(40);
});

test('duplication and complexity findings cost points but never more than the size cap', function () {
    // laravel/framework: 293 duplication and 159 complexity mediums in 357k lines took the score to 0.
    $sizeScaling = [...array_fill(0, 293, scoreFinding('medium', 'duplication')), ...array_fill(0, 159, scoreFinding('medium', 'complexity'))];
    $framework = calculator()->calculate(new FindingCollection($sizeScaling), 356590);
    $withDefects = calculator()->calculate(new FindingCollection([...$sizeScaling, ...array_fill(0, 20, scoreFinding('high', 'security'))]), 356590);
    $few = calculator()->calculate(new FindingCollection([scoreFinding('medium', 'duplication')]), 5000);

    expect($framework->score)->toBe(85)->and($framework->sizePenalty)->toBe(15)->and($framework->sizeCount)->toBe(452)->and($framework->penalty)->toBe(0)
        // Defect findings keep their full weight on top of the capped size penalty.
        ->and($withDefects->penalty)->toBe(200)->and($withDefects->sizePenalty)->toBe(15)->and($withDefects->score)->toBeLessThan(80)
        ->and($few->score)->toBe(99)->and($few->sizePenalty)->toBe(1)
        ->and(ScoreResult::fromArray($framework->toArray())->sizePenalty)->toBe(15);
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
