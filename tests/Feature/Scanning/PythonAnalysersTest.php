<?php

use App\Scanning\Analysers\RuffAnalyser;
use App\Scanning\Data\Finding;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;

test('ruff reports pyflakes, error-handling and security problems with our categories and severities', function () {
    $workspace = temporaryWorkspace();
    $repo = $workspace->repoPath();
    @mkdir($repo.'/app', 0777, true);
    @mkdir($repo.'/tests', 0777, true);
    file_put_contents($repo.'/app/service.py', "import os\nimport subprocess\n\n\ndef total(items):\n    result = undefined_helper(items)\n    try:\n        return result + 1\n    except:\n        pass\n\n\ndef run(cmd):\n    return subprocess.call(cmd, shell=True)\n");
    file_put_contents($repo.'/tests/test_service.py', "import subprocess\n\n\ndef test_run():\n    assert subprocess.call('ls', shell=True) == 0\n");

    $findings = app(RuffAnalyser::class)->run($repo);
    $byRule = [];
    foreach ($findings as $finding) {
        $byRule[(string) $finding->ruleId][] = $finding;
    }

    expect($byRule)->toHaveKeys(['F401', 'F821', 'E722', 'S602'])
        ->and($byRule['F401'][0]->category)->toBe(FindingCategory::DeadCode)->and($byRule['F401'][0]->severity)->toBe(Severity::Low)
        ->and($byRule['F821'][0]->category)->toBe(FindingCategory::TypeSafety)->and($byRule['F821'][0]->severity)->toBe(Severity::High)
        ->and($byRule['E722'][0]->category)->toBe(FindingCategory::ErrorHandling)->and($byRule['E722'][0]->severity)->toBe(Severity::Medium)
        ->and($byRule['S602'][0]->category)->toBe(FindingCategory::Security)->and($byRule['S602'][0]->severity)->toBe(Severity::High)
        ->and($byRule['S602'][0]->filePath)->toBe('app/service.py')
        // Security rules and asserts are not reported inside tests (per-file ignores in the bundled config).
        ->and(array_filter($findings->all(), fn (Finding $f) => str_starts_with($f->filePath, 'tests/') && str_starts_with((string) $f->ruleId, 'S')))->toBe([])
        ->and($byRule['F821'][0]->snippet)->toContain('undefined_helper');
});

test('ruff runs for Python stacks only and reports a repository where it sees no files', function () {
    $analyser = app(RuffAnalyser::class);

    expect($analyser->supports(new Stack(['Python' => 100])))->toBeTrue()
        ->and($analyser->supports(new Stack([], [], [], ['requirements.txt'])))->toBeTrue()
        ->and($analyser->supports(new Stack(['PHP' => 100])))->toBeFalse();
});
