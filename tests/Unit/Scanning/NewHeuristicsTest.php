<?php

use App\Scanning\Data\Finding;
use App\Scanning\Heuristics\SuppressionDensityHeuristic;
use App\Scanning\Heuristics\UndefinedMembersHeuristic;

test('undefined methods are reported only on fully resolvable classes', function () {
    $findings = (new UndefinedMembersHeuristic)->run(fixturePath('sloppy-laravel'));
    $messages = array_map(fn (Finding $f) => $f->filePath.':'.$f->line.' '.$f->message, $findings->all());
    sort($messages);

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('app/Services/InvoiceService.php:16', 'App\Services\InvoiceService->missingTotal()')
        ->and($messages[1])->toContain('app/Services/InvoiceService.php:17', 'App\Support\Formatter->formatMoney()');
});

test('calls on classes with unresolvable parents are not reported', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/M.php', "<?php\nnamespace App;\nclass M extends \Illuminate\Database\Eloquent\Model { public function a(): void { \$this->nope(); self::where('x', 1); } }\n");
    file_put_contents($workspace->repoPath().'/Magic.php', "<?php\nnamespace App;\nclass Magic { public function __call(\$n, \$a) {} public function a(): void { \$this->anything(); } }\n");
    file_put_contents($workspace->repoPath().'/Ex.php', "<?php\nnamespace App;\nclass Ex extends \RuntimeException { public function a(): string { return \$this->getMessage() . \$this->bogus(); } }\n");
    file_put_contents($workspace->repoPath().'/T.php', "<?php\nnamespace App;\ntrait T { public function fromTrait(): int { return 1; } }\nclass UsesT { use T; public function a(): int { return \$this->fromTrait(); } }\n");

    $findings = (new UndefinedMembersHeuristic)->run($workspace->repoPath());

    expect(array_map(fn (Finding $f) => $f->filePath.' '.$f->message, $findings->all()))->toHaveCount(1)
        ->and($findings->all()[0]->filePath)->toBe('Ex.php')
        ->and($findings->all()[0]->message)->toContain('bogus()');
});

test('suppression comments are counted per thousand lines and reported as findings', function () {
    $stats = (new SuppressionDensityHeuristic)->analyse(fixturePath('sloppy-laravel'));

    expect($stats['count'])->toBe(2)
        ->and($stats['by_kind'])->toBe(['phpstan' => 1, 'phpcs' => 1])
        ->and($stats['lines'])->toBeGreaterThan(100)
        ->and($stats['density'])->toBe(round(2 / $stats['lines'] * 1000, 2))
        ->and($stats['findings'])->toHaveCount(2)
        ->and($stats['findings']->all()[0]->ruleId)->toBe('inline-suppression');

    $ts = (new SuppressionDensityHeuristic)->analyse(fixturePath('sloppy-ts'));
    expect($ts['by_kind'])->toBe(['eslint' => 1]);
});
