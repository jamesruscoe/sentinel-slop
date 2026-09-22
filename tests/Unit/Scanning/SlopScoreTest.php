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
    return new SlopScoreCalculator(['critical' => 25, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0], scale: 1.0, criticalCap: 40, suppressionWeight: 2.0, suppressionCap: 20);
}

test('a clean repository scores 100', function () {
    $result = calculator()->calculate(new FindingCollection, 1000);

    expect($result->score)->toBe(100)->and($result->penalty)->toBe(0)->and($result->criticalCapApplied)->toBeFalse();
});

test('penalty is weighted by severity and normalised per thousand lines', function () {
    $findings = new FindingCollection([scoreFinding('high'), scoreFinding('medium'), scoreFinding('medium'), scoreFinding('low'), scoreFinding('info')]);

    $result = calculator()->calculate($findings, 2000);

    // penalty 19 over 2000 lines = 9.5 per 1k => 100 - 10 = 90
    expect($result->penalty)->toBe(19)
        ->and($result->density)->toBe(9.5)
        ->and($result->score)->toBe(90)
        ->and($result->bySeverity)->toBe(['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 1, 'info' => 1]);
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

test('score results round-trip and config weights are honoured', function () {
    $calc = SlopScoreCalculator::fromConfig(['weights' => ['high' => 50], 'scale' => 0.5, 'critical_cap' => 10]);
    $result = $calc->calculate(new FindingCollection([scoreFinding('high')]), 1000);

    expect($result->score)->toBe(75)
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
