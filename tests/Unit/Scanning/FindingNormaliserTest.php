<?php

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Normalise\FindingNormaliser;
use App\Scanning\Normalise\SecretRedactor;

function finding(array $overrides = []): Finding
{
    return Finding::fromArray(array_merge([
        'tool' => 'phpstan', 'rule_id' => 'x', 'category' => 'type_safety', 'severity' => 'medium',
        'file_path' => 'app/A.php', 'line' => 10, 'message' => 'msg', 'snippet' => null,
    ], $overrides));
}

test('paths are made repo-relative with forward slashes and snippets are clamped', function () {
    $repo = 'C:/scans/abc/repo';
    $long = implode("\n", array_map(fn ($i) => "line {$i} ".str_repeat('x', 400), range(1, 10)));

    $result = (new FindingNormaliser(maxSnippetLines: 3))->normalise(new FindingCollection([
        finding(['file_path' => 'C:\scans\abc\repo\app\A.php', 'snippet' => $long, 'line' => 0]),
        finding(['file_path' => './src/b.ts', 'message' => str_repeat('m', 2000)]),
    ]), $repo);

    [$a, $b] = $result->all();
    expect($a->filePath)->toBe('app/A.php')
        ->and($a->line)->toBeNull()
        ->and(substr_count((string) $a->snippet, "\n"))->toBe(2)
        ->and(mb_strlen(explode("\n", (string) $a->snippet)[0]))->toBe(300)
        ->and($b->filePath)->toBe('src/b.ts')
        ->and(mb_strlen($b->message))->toBe(1000);
});

test('security, slop, error-handling and complexity findings in test files become Low with a caveat', function () {
    // Django: 197 hard-coded passwords and Laravel: eval() in a memoisation test were Medium/High security findings.
    $result = (new FindingNormaliser)->normalise(new FindingCollection([
        finding(['category' => 'security', 'severity' => 'high', 'file_path' => 'tests/Support/OnceTest.php', 'message' => 'eval() executes arbitrary strings as code.']),
        finding(['category' => 'security', 'severity' => 'medium', 'file_path' => 'tests/auth_tests/test_views.py', 'line' => 3]),
        finding(['category' => 'security', 'severity' => 'medium', 'file_path' => 'app/Http/Login.php']),
        finding(['category' => 'secrets', 'severity' => 'critical', 'file_path' => 'tests/keys_test.php', 'tool' => 'gitleaks']),
        finding(['category' => 'type_safety', 'severity' => 'medium', 'file_path' => 'tests/Unit/ATest.php', 'line' => 4]),
    ]));
    $bySeverity = [];
    foreach ($result->all() as $finding) {
        $bySeverity[$finding->filePath] = [$finding->severity, $finding->message];
    }

    expect($bySeverity['tests/Support/OnceTest.php'][0])->toBe(Severity::Low)
        ->and($bySeverity['tests/Support/OnceTest.php'][1])->toBe('eval() executes arbitrary strings as code. This is in a test file: confirm it matters outside the test before treating it as a defect.')
        ->and($bySeverity['tests/auth_tests/test_views.py'][0])->toBe(Severity::Low)
        ->and($bySeverity['app/Http/Login.php'][0])->toBe(Severity::Medium)
        ->and($bySeverity['tests/keys_test.php'][0])->toBe(Severity::Critical)
        ->and($bySeverity['tests/Unit/ATest.php'][0])->toBe(Severity::Medium);
});

test('secret findings keep only file, line and type', function () {
    $secret = new FindingCollection([finding([
        'tool' => 'gitleaks', 'category' => 'secrets', 'severity' => 'critical', 'rule_id' => 'aws-access-key',
        'message' => 'Found AWS key AKIAIOSFODNN7EXAMPLE1234 in config',
        'snippet' => 'AWS_KEY=AKIAIOSFODNN7EXAMPLE1234',
    ])]);

    $result = (new FindingNormaliser)->normalise($secret)->all()[0];

    expect($result->snippet)->toBeNull()
        ->and($result->message)->not->toContain('AKIAIOSFODNN7EXAMPLE1234')
        ->and($result->message)->toContain(SecretRedactor::PLACEHOLDER)
        ->and($result->ruleId)->toBe('aws-access-key');
});

test('known secret values can be scrubbed from arbitrary text', function () {
    expect(SecretRedactor::redactValue('token=ghp_abc123 ok', 'ghp_abc123'))->toBe('token=[REDACTED] ok')
        ->and(SecretRedactor::scrub('password: "hunter2hunter2"'))->toBe('password: "[REDACTED]"');
});

test('duplicates collapse and the most severe wins', function () {
    $result = (new FindingNormaliser)->normalise(new FindingCollection([
        finding(['severity' => 'low']),
        finding(['severity' => 'high']),
        finding(['tool' => 'semgrep', 'rule_id' => 'other', 'severity' => 'critical']),
        finding(['line' => 11]),
        finding(['tool' => 'pint', 'rule_id' => 'style', 'category' => 'style', 'line' => null]),
        finding(['tool' => 'pint', 'rule_id' => 'style', 'category' => 'style', 'line' => null, 'file_path' => 'app/B.php']),
    ]));

    expect($result)->toHaveCount(4)
        ->and($result->all()[0]->severity)->toBe(Severity::Critical)
        ->and($result->all()[0]->tool)->toBe('semgrep')
        ->and(array_map(fn (Finding $f) => $f->severity->value, $result->all()))->toBe(['critical', 'medium', 'medium', 'medium']);
});

test('finding collections round-trip through arrays', function () {
    $collection = new FindingCollection([finding(), finding(['category' => FindingCategory::Slop->value, 'line' => null])]);

    expect(FindingCollection::fromArray($collection->toArray())->toArray())->toBe($collection->toArray())
        ->and($collection->withSeverity(Severity::Medium))->toHaveCount(2)
        ->and($collection->securityCritical())->toHaveCount(0);
});
