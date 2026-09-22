<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Heuristics\HallucinatedDependenciesHeuristic;
use App\Scanning\Heuristics\NarratingCommentsHeuristic;
use App\Scanning\Heuristics\NearDuplicateFunctionsHeuristic;
use App\Scanning\Heuristics\OversizedUnitsHeuristic;
use App\Scanning\Heuristics\PlaceholderCodeHeuristic;
use App\Scanning\Heuristics\SwallowedExceptionsHeuristic;

function rulesAt(iterable $findings, string $file): array
{
    $out = [];
    foreach ($findings as $f) {
        if ($f->filePath === $file) {
            $out[] = $f->ruleId.'@'.($f->line ?? '-');
        }
    }
    sort($out);

    return $out;
}

test('narrating comments are flagged, useful comments are not', function () {
    $findings = (new NarratingCommentsHeuristic)->run(fixturePath('sloppy-laravel'));

    expect(rulesAt($findings, 'app/Services/OrderService.php'))->toBe(['narrating-comment@13', 'narrating-comment@16'])
        ->and(collect($findings->all())->every(fn (Finding $f) => $f->category === FindingCategory::Slop))->toBeTrue();
});

test('swallowed and log-only catch blocks are flagged', function () {
    $findings = (new SwallowedExceptionsHeuristic)->run(fixturePath('sloppy-laravel'));

    expect(rulesAt($findings, 'app/Services/OrderService.php'))->toBe(['catch-only-logs@32', 'empty-catch@24']);
});

test('undeclared PHP namespaces and npm packages are flagged, transitive and local ones are not', function () {
    $php = (new HallucinatedDependenciesHeuristic)->run(fixturePath('sloppy-laravel'));
    $messages = array_map(fn (Finding $f) => $f->message, $php->all());

    expect($php)->toHaveCount(1)
        ->and($messages[0])->toContain('Acme')
        ->and($php->all()[0]->filePath)->toBe('app/Services/OrderService.php');

    $js = (new HallucinatedDependenciesHeuristic)->run(fixturePath('sloppy-ts'));

    expect($js)->toHaveCount(1)
        ->and($js->all()[0]->message)->toContain('"left-pad-ultra"')
        ->and($js->all()[0]->line)->toBe(3);
});

test('stubs, todo markers and placeholder data are flagged', function () {
    $findings = (new PlaceholderCodeHeuristic)->run(fixturePath('sloppy-laravel'));

    expect(rulesAt($findings, 'app/Services/OrderService.php'))->toBe([
        'empty-stub@79', 'not-implemented-stub@68', 'placeholder-data@75', 'todo-marker@78',
    ]);
});

test('near-duplicate functions are paired up', function () {
    $findings = (new NearDuplicateFunctionsHeuristic)->run(fixturePath('sloppy-laravel'));

    expect($findings)->toHaveCount(1)
        ->and($findings->all()[0]->ruleId)->toBe('duplicate-function')
        ->and($findings->all()[0]->message)->toContain('computeCartTotal()', 'calculateOrderTotal()')
        ->and($findings->all()[0]->category)->toBe(FindingCategory::Duplication);
});

test('oversized files and functions are flagged', function () {
    $findings = (new OversizedUnitsHeuristic(600, 80))->run(fixturePath('sloppy-laravel'));

    expect(rulesAt($findings, 'app/Http/Controllers/ReportController.php'))->toBe(['oversized-function@7'])
        ->and(rulesAt($findings, 'app/Support/Huge.php'))->toBe(['oversized-file@-']);
});

test('heuristics declare which stacks they apply to', function () {
    $php = new Stack(['PHP' => 10]);
    $js = new Stack(['TypeScript' => 10]);

    expect((new SwallowedExceptionsHeuristic)->supports($js))->toBeFalse()
        ->and((new SwallowedExceptionsHeuristic)->supports($php))->toBeTrue()
        ->and((new NarratingCommentsHeuristic)->supports($js))->toBeTrue()
        ->and((new HallucinatedDependenciesHeuristic)->supports($js))->toBeTrue();
});
