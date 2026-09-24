<?php

use App\Scanning\Analysers\EslintAnalyser;
use App\Scanning\Analysers\JscpdAnalyser;
use App\Scanning\Analysers\SemgrepAnalyser;
use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\AnalyserOptions;
use App\Scanning\Data\Finding;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Process\ToolLocator;

beforeEach(function () {
    $this->workspace = workspaceFromFixture('sloppy-ts');
});

test('eslint reports slop with non-type-aware rules and ignores inline disables', function () {
    $findings = app(EslintAnalyser::class)->run($this->workspace->repoPath());
    $rules = array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all()));
    $byFile = fn (string $file) => array_values(array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, array_filter($findings->all(), fn (Finding $f) => $f->filePath === $file))));

    expect($rules)->toContain('eqeqeq', 'no-var', 'no-empty', '@typescript-eslint/no-explicit-any', '@typescript-eslint/no-unused-vars', 'max-lines-per-function')
        ->and($byFile('src/disabled.ts'))->toContain('eqeqeq', 'no-var')
        ->and($byFile('src/long.ts'))->toContain('max-lines-per-function')
        ->and(collect($findings->all())->first(fn (Finding $f) => $f->ruleId === 'max-lines-per-function')->category)->toBe(FindingCategory::Complexity);
});

test('jscpd finds the duplicated address normaliser', function () {
    $findings = app(JscpdAnalyser::class)->run($this->workspace->repoPath());

    expect($findings)->toHaveCount(1)
        ->and($findings->all()[0]->category)->toBe(FindingCategory::Duplication)
        ->and($findings->all()[0]->filePath)->toBe('src/dupB.ts')
        ->and($findings->all()[0]->message)->toContain('src/dupA.ts');
});

test('a parse error in a templates or fixtures directory is Low with a caveat, elsewhere High', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/app/views/templates', 0777, true);
    @mkdir($workspace->repoPath().'/resources/js', 0777, true);
    // Django's i18n_catalog.js is a template rendered with {% %} tags, not JavaScript.
    file_put_contents($workspace->repoPath().'/app/views/templates/catalog.js', "{% autoescape off %}\nconst catalog = {{ catalog_str }};\n{% endautoescape %}\n");
    file_put_contents($workspace->repoPath().'/resources/js/broken.js', "const x = ;\n");

    $findings = app(EslintAnalyser::class)->run($workspace->repoPath());
    $bySeverity = [];
    foreach ($findings->all() as $finding) {
        $bySeverity[$finding->filePath] = $finding->severity;
    }

    expect($bySeverity['app/views/templates/catalog.js'])->toBe(Severity::Low)
        ->and($bySeverity['resources/js/broken.js'])->toBe(Severity::High)
        ->and($findings->all()[0]->ruleId)->toBe('parse-error');
});

test('jscpd compares Vue single-file components whole, so page-level duplication is found', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/resources/js/pages/Staff/Dogs', 0777, true);
    @mkdir($workspace->repoPath().'/resources/js/pages/Owner/Dogs', 0777, true);
    $script = "<script setup lang=\"ts\">\n";
    for ($i = 1; $i <= 12; $i++) {
        $script .= "const filtered{$i} = computed(() => props.dogs.filter((dog) => dog.name.toLowerCase().includes(search.value.toLowerCase()) && dog.id !== {$i}))\n";
    }
    $script .= "</script>\n<template>\n";
    for ($i = 1; $i <= 6; $i++) {
        $script .= "  <li v-for=\"dog in filtered{$i}\" :key=\"dog.id\" class=\"flex items-center justify-between py-2\"><span>{{ dog.name }} ({{ dog.breed }})</span><button type=\"button\" @click=\"remove(dog.id)\">Remove {$i}</button></li>\n";
    }
    $script .= "</template>\n";
    file_put_contents($workspace->repoPath().'/resources/js/pages/Staff/Dogs/Index.vue', $script);
    file_put_contents($workspace->repoPath().'/resources/js/pages/Owner/Dogs/Index.vue', $script);

    $findings = app(JscpdAnalyser::class)->run($workspace->repoPath());

    expect($findings)->toHaveCount(1)
        ->and($findings->all()[0]->filePath)->toBe('resources/js/pages/Staff/Dogs/Index.vue')
        ->and($findings->all()[0]->message)->toContain('resources/js/pages/Owner/Dogs/Index.vue')
        ->and($findings->all()[0]->filePath)->not->toContain(':');
});

test('a duplicated class skeleton with one real statement is boilerplate, not a duplication finding', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/app/Http/Requests', 0777, true);
    foreach (['StoreDog', 'UpdateDog', 'StoreOwner'] as $name) {
        file_put_contents($workspace->repoPath()."/app/Http/Requests/{$name}Request.php", "<?php\n\nnamespace App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass {$name}Request extends FormRequest\n{\n    /**\n     * Determine if the user is authorised.\n     */\n    public function authorize(): bool\n    {\n        return \$this->user() !== null;\n    }\n\n    public function rules(): array\n    {\n        return ['{$name}' => ['required']];\n    }\n}\n");
    }

    expect(app(JscpdAnalyser::class)->run($workspace->repoPath()))->toHaveCount(0)
        ->and(JscpdAnalyser::statementsIn("<?php\nnamespace A;\nuse B;\nclass C extends D\n{\n    public function authorize(): bool\n    {\n        return \$this->user() !== null;\n    }\n}\n"))->toBe(1)
        ->and(JscpdAnalyser::statementsIn("\$a = 1;\n\$b = \$a + 2;\nif (\$b > 2) {\n    return \$b;\n}\nreturn null;\n"))->toBe(5);
});

test('jscpd does not report the skeleton header every migration shares', function () {
    $workspace = temporaryWorkspace();
    @mkdir($workspace->repoPath().'/database/migrations', 0777, true);
    @mkdir($workspace->repoPath().'/app', 0777, true);
    $header = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('things', function (Blueprint \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('things');\n    }\n};\n";
    file_put_contents($workspace->repoPath().'/database/migrations/0001_01_01_000000_create_a_table.php', $header);
    file_put_contents($workspace->repoPath().'/database/migrations/0001_01_01_000001_create_b_table.php', $header);
    // The same block outside migrations is still duplication, and keeps the analyser from seeing zero files.
    file_put_contents($workspace->repoPath().'/app/A.php', $header);
    file_put_contents($workspace->repoPath().'/app/B.php', $header);

    $files = array_map(fn (Finding $f) => $f->filePath, app(JscpdAnalyser::class)->run($workspace->repoPath())->all());

    expect($files)->toBe(['app/B.php']);
});

test('jscpd ignores every .gitignore, including one in a parent directory that excludes the workspace', function () {
    // Real scans live under storage/app/scans, which Sentinel Slop's own .gitignore excludes; jscpd 5 walks parent
    // .gitignore files and used to analyse nothing. A repository's own .gitignore must not hide files either.
    $workspace = temporaryWorkspace();
    file_put_contents(dirname($workspace->root).'/.gitignore', "*\n");
    file_put_contents($workspace->repoPath().'/.gitignore', "src/\n*.ts\n");
    @mkdir($workspace->repoPath().'/src', 0777, true);
    $body = '';
    for ($i = 1; $i <= 14; $i++) {
        $body .= "export function normalise{$i}(input: string): string {\n    return input.trim().toLowerCase().replace(/\\s+/g, ' ').replace(/[^a-z0-9 ]/g, '') + '{$i}'\n}\n";
    }
    file_put_contents($workspace->repoPath().'/src/dupA.ts', $body);
    file_put_contents($workspace->repoPath().'/src/dupB.ts', $body);

    try {
        $findings = app(JscpdAnalyser::class)->run($workspace->repoPath());
    } finally {
        @unlink(dirname($workspace->root).'/.gitignore');
    }

    expect($findings)->toHaveCount(1)->and($findings->all()[0]->message)->toContain('src/dupA.ts');
});

test('the semgrep slop rules catch swallowed errors, stubs and placeholder data in TypeScript', function () {
    $semgrep = new SemgrepAnalyser(app(ProcessRunner::class), app(ToolLocator::class), app(AnalyserOptions::class), 'slop', 'semgrep-slop');
    $findings = $semgrep->run($this->workspace->repoPath());
    $rules = array_unique(array_map(fn (Finding $f) => (string) $f->ruleId, $findings->all()));

    expect($rules)->toContain(
        'sentinel.slop.js.empty-catch',
        'sentinel.slop.js.catch-only-logs',
        'sentinel.slop.js.not-implemented-stub',
        'sentinel.slop.js.placeholder-data',
    )->and(collect($findings->all())->first(fn (Finding $f) => $f->ruleId === 'sentinel.slop.js.empty-catch')->category)->toBe(FindingCategory::ErrorHandling);
});
