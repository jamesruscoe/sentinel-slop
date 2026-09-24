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

test('interface-typed receivers and trait aliases are never undefined', function () {
    $workspace = temporaryWorkspace();
    // laravel/framework: Authenticatable $user calling save() (an Eloquent model implements it) and
    // `use RetrievesMultipleKeys { many as manyAlias; }` were both reported as undefined methods.
    file_put_contents($workspace->repoPath().'/Contract.php', "<?php\nnamespace App;\ninterface Contract { public function id(): int; }\nabstract class Base { abstract public function run(): void; }\n");
    file_put_contents($workspace->repoPath().'/Caller.php', "<?php\nnamespace App;\nclass Caller { public function __construct(private Contract \$c, private Base \$b) {} public function a(Contract \$user, Base \$job): void { \$user->save(); \$job->retry(); \$this->c->save(); \$this->b->retry(); } }\n");
    file_put_contents($workspace->repoPath().'/T.php', "<?php\nnamespace App;\ntrait T { public function many(): int { return 1; } }\nclass Store { use T { many as manyAlias; } public function a(): int { return \$this->manyAlias() + \$this->reallyMissing(); } }\n");
    // A base class every notification extends: toMail() lives on the subclasses (Illuminate\Notifications\Notification).
    file_put_contents($workspace->repoPath().'/N.php', "<?php\nnamespace App;\nclass Notification {}\nclass Welcome extends Notification { public function toMail(): string { return 'x'; } }\nclass MailChannel { public function send(Notification \$n): string { return \$n->toMail(); } }\n");
    // `\$this` inside an anonymous class is that class (AsEnumArrayObject::castUsing()).
    file_put_contents($workspace->repoPath().'/Anon.php', "<?php\nnamespace App;\nclass Outer { public function make(): object { return new class { public function b(): int { return \$this->fromTrait(); } }; } }\n");

    $findings = (new UndefinedMembersHeuristic)->run($workspace->repoPath());

    expect(array_map(fn (Finding $f) => $f->filePath.' '.$f->message, $findings->all()))->toHaveCount(1)
        ->and($findings->all()[0]->message)->toContain('reallyMissing()');
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
