<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Detect\StackDetector;
use App\Scanning\Normalise\FindingNormaliser;
use App\Scanning\Normalise\PhpSymbolLocator;

test('the enclosing class and method are resolved from the AST, and nothing is guessed', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/app/Services', 0700, true);
    file_put_contents($workspace->repoPath().'/app/Services/Invoice.php', <<<'PHP'
<?php

namespace App\Services;

use App\Support\Formatter;

class Invoice
{
    private int $x = 1;

    public function render(int $pence): string
    {
        $fn = function () {
            return $this->missing();
        };

        return (string) $pence;
    }
}

function helper(): int
{
    return 1;
}

$anon = new class {
    public function go(): void {}
};
PHP);
    file_put_contents($workspace->repoPath().'/broken.php', '<?php class {');
    file_put_contents($workspace->repoPath().'/view.blade.php', '<?php echo 1;');

    $locator = new PhpSymbolLocator($workspace->repoPath());

    expect($locator->locate('app/Services/Invoice.php', 9))->toBe('App\Services\Invoice')
        ->and($locator->locate('app/Services/Invoice.php', 14))->toBe('App\Services\Invoice::render()')
        ->and($locator->locate('app/Services/Invoice.php', 21))->toBe('App\Services\helper()')
        ->and($locator->locate('app/Services/Invoice.php', 5))->toBeNull()
        ->and($locator->locate('app/Services/Invoice.php', 27))->toBeNull()
        ->and($locator->locate('app/Services/Invoice.php', null))->toBeNull()
        ->and($locator->locate('broken.php', 1))->toBeNull()
        ->and($locator->locate('view.blade.php', 1))->toBeNull()
        ->and($locator->locate('missing.php', 1))->toBeNull();
});

test('the normaliser attaches symbols to PHP findings and the location string carries them', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/A.php', "<?php\nnamespace App;\nclass A {\n    public function b(): void {\n        \$x = 1;\n    }\n}\n");
    $raw = new FindingCollection([
        Finding::fromArray(['tool' => 'phpstan', 'rule_id' => 'r', 'category' => 'style', 'severity' => 'low', 'file_path' => $workspace->repoPath().'/A.php', 'line' => 5, 'message' => 'm']),
        Finding::fromArray(['tool' => 'eslint', 'rule_id' => 'r', 'category' => 'style', 'severity' => 'low', 'file_path' => 'x.js', 'line' => 5, 'message' => 'm']),
        Finding::fromArray(['tool' => 'x', 'rule_id' => 'r', 'category' => 'style', 'severity' => 'low', 'file_path' => 'A.php', 'line' => 5, 'message' => 'm', 'symbol' => 'Given::bySomeTool()']),
    ]);

    $findings = (new FindingNormaliser)->normalise($raw, $workspace->repoPath())->all();
    $bySymbol = array_map(fn (Finding $f) => [$f->tool, $f->symbol, $f->location()], $findings);

    expect($bySymbol)->toContain(['phpstan', 'App\A::b()', 'A.php:5 in App\A::b()'])
        ->and($bySymbol)->toContain(['eslint', null, 'x.js:5']);
});

test('declared framework and runtime versions are detected from manifests', function () {
    $workspace = temporaryWorkspace();
    file_put_contents($workspace->repoPath().'/composer.json', json_encode([
        'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0', 'livewire/livewire' => '3.5.*'],
        'require-dev' => ['larastan/larastan' => '^3.0'],
    ]));
    file_put_contents($workspace->repoPath().'/package.json', json_encode([
        'engines' => ['node' => '>=20'],
        'dependencies' => ['react' => '^18.3.1', 'next' => '15.0.0'],
        'devDependencies' => ['typescript' => '~5.6', 'eslint' => '9'],
    ]));

    $stack = (new StackDetector)->detect($workspace->repoPath(), ['PHP' => 900, 'TypeScript' => 100], ['composer.json', 'package.json']);

    expect($stack->versions)->toBe(['laravel' => '^12.0', 'livewire' => '3.5.*', 'php' => '^8.2', 'react' => '^18.3.1', 'next' => '15.0.0', 'typescript' => '~5.6', 'node' => '>=20'])
        ->and($stack->version('laravel'))->toBe('12')
        ->and($stack->version('react'))->toBe('18.3')
        ->and($stack->version('php'))->toBe('8.2')
        ->and($stack->version('vue'))->toBeNull()
        ->and($stack->describeFrameworks())->toBe('laravel 12, livewire 3.5, next 15, react 18.3')
        ->and($stack->describeRuntimes())->toBe('PHP 8.2, Node 20, TypeScript 5.6')
        ->and(Stack::fromArray($stack->toArray())->versions)->toBe($stack->versions);
});
