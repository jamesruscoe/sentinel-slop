<?php

use App\Scanning\Analysers\GitleaksAnalyser;
use App\Scanning\Analysers\PhpStanAnalyser;
use App\Scanning\Analysers\PintAnalyser;
use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Process\ToolLocator;

beforeEach(function () {
    $this->workspace = workspaceFromFixture('sloppy-laravel');
});

test('phpstan reports real type problems without drowning in unknown-class noise', function () {
    $findings = app(PhpStanAnalyser::class)->run($this->workspace->repoPath());
    $identifiers = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());
    $files = array_unique(array_map(fn (Finding $f) => $f->filePath, $findings->all()));

    expect($identifiers)->toContain('return.type')
        ->and($identifiers)->not->toContain('class.notFound', 'method.notFound', 'staticMethod.notFound', 'property.notFound')
        ->and($findings->count())->toBeLessThan(25)
        ->and($files)->not->toContain('resources/views/order.blade.php')
        ->and($findings->all()[0]->category)->toBe(FindingCategory::TypeSafety);
});

test('pint reports files that differ from the laravel preset', function () {
    $findings = app(PintAnalyser::class)->run($this->workspace->repoPath());
    $files = array_map(fn (Finding $f) => $f->filePath, $findings->all());

    expect($files)->toContain('app/Models/Order.php')
        ->and($files)->not->toContain('resources/views/order.blade.php')
        ->and($findings->all()[0]->category)->toBe(FindingCategory::Style);
});

test('gitleaks finds a hard-coded api key and reports only its type and location', function () {
    $findings = app(GitleaksAnalyser::class)->run($this->workspace->repoPath());
    $hit = collect($findings->all())->first(fn (Finding $f) => $f->filePath === 'app/Services/PaymentClient.php');

    expect($hit)->not->toBeNull()
        ->and($hit->category)->toBe(FindingCategory::Secrets)
        ->and($hit->line)->toBe(7)
        ->and($hit->snippet)->toBeNull()
        ->and($hit->message)->not->toContain('Qm7xLp2Z');
});

test('phpstan drops non-ignorable unknown-symbol errors when the symbol resolves to a package in composer.lock', function () {
    $workspace = workspaceFromFixture('lockfile-deps');
    $findings = app(PhpStanAnalyser::class)->run($workspace->repoPath());
    $identifiers = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());
    $messages = implode("\n", array_map(fn (Finding $f) => $f->message, $findings->all()));

    expect($identifiers)->not->toContain('class.notFound', 'interface.notFound', 'trait.notFound', 'class.noParent')
        ->and($messages)->not->toContain('Inertia\Middleware', 'Spatie\MediaLibrary', 'Tighten\Ziggy');
});

test('the same errors are kept when the repository is not a Laravel application', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/composer.json', '{"require": {"php": "^8.2"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');
    @mkdir($workspace->repoPath().'/app');
    file_put_contents($workspace->repoPath().'/app/Thing.php', "<?php\n\nnamespace App;\n\nclass Thing\n{\n    public function total(): int\n    {\n        return '0';\n    }\n\n    public function name(?Thing \$other): string\n    {\n        \$self = \$this;\n\n        return \$self?->label ?? 'x';\n    }\n\n    public string \$label = 'y';\n}\n");

    $identifiers = array_map(fn ($f) => (string) $f->ruleId, app(PhpStanAnalyser::class)->run($workspace->repoPath())->all());

    // `$self?->label` on a non-nullable local is "never null" to PHPStan; telling a user to remove such guards has
    // turned an empty page into a 500 when the type came from the framework, so the identifier is dropped outright.
    expect($identifiers)->toContain('return.type')->not->toContain('nullsafe.neverNull');
});

test('phpstan runs from a detached phar and never sees our own vendor symbols', function () {
    $command = app(ToolLocator::class)->command('phpstan');
    $phar = str_replace(chr(92), '/', $command[1]);

    expect($phar)->toEndWith('/phpstan.phar')
        ->and($phar)->not->toContain('/vendor/')
        ->and(is_file($phar))->toBeTrue();

    $workspace = workspaceFromFixture('lockfile-deps');
    $messages = array_map(fn (Finding $f) => $f->message, app(PhpStanAnalyser::class)->run($workspace->repoPath())->all());

    // With no autoloader in reach every vendor type is unknown; those errors are ignored or dropped,
    // so nothing PHPStan reports can be a judgement about a framework version we ship.
    expect(implode("\n", $messages))->not->toContain('Illuminate'.chr(92))->not->toContain('Carbon'.chr(92))->not->toContain('Symfony'.chr(92));
});

test('$this inside a closure in a route file is framework-bound and not reported, the same code elsewhere is', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/composer.json', '{"require": {"laravel/framework": "^12.0"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}');
    @mkdir($workspace->repoPath().'/routes');
    @mkdir($workspace->repoPath().'/app');
    $closure = "Artisan::command('inspire', function () {\n    \$this->comment('Keep going');\n})->purpose('Display an inspiring quote');\n";
    file_put_contents($workspace->repoPath().'/routes/console.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Artisan;\n\n".$closure);
    file_put_contents($workspace->repoPath().'/app/helpers.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Artisan;\n\n".$closure);

    $findings = app(PhpStanAnalyser::class)->run($workspace->repoPath());
    $undefinedThis = array_map(fn (Finding $f) => $f->filePath, array_filter($findings->all(), fn (Finding $f) => $f->ruleId === 'variable.undefined' && str_contains($f->message, '$this')));

    expect($undefinedThis)->toBe(['app/helpers.php']);
});

test('errors that only exist because a dependency is invisible are dropped, real ones stay', function () {
    $workspace = workspaceFromFixture('lockfile-deps');
    $findings = app(PhpStanAnalyser::class)->run($workspace->repoPath());
    $identifiers = array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all());

    // new CareLogResource($log) with an invisible JsonResource parent, @throws on an invisible exception class.
    expect($identifiers)->not->toContain('new.noConstructor', 'throws.notThrowable', 'class.noParent')
        ->and($identifiers)->toContain('return.type')
        ->and(array_filter($findings->all(), fn (Finding $f) => $f->ruleId === 'return.type'))->toHaveCount(1);
});
