<?php

use App\Enums\ScanStatus;
use App\Models\Finding;
use App\Models\Installation;
use App\Models\Prompt;
use App\Models\Repository;
use App\Models\RulesFile;
use App\Models\Scan;
use App\Models\User;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use App\Scanning\Enums\TargetEditor;

test('relationships and enum casts round-trip', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->for(Installation::factory()->for($user))->create();
    $scan = Scan::factory()->for($repository)->for($user)->create();
    Finding::factory()->for($scan)->critical()->create();
    Prompt::factory()->for($scan)->create(['target_editor' => TargetEditor::Cursor, 'phase' => 2]);
    RulesFile::factory()->for($scan)->create();

    $scan->refresh();

    expect($scan->uuid)->toBeString()->toHaveLength(36)
        ->and($scan->status)->toBe(ScanStatus::Queued)
        ->and($scan->findings->first()->severity)->toBe(Severity::Critical)
        ->and($scan->findings->first()->category)->toBe(FindingCategory::Secrets)
        ->and($scan->prompts->first()->target_editor)->toBe(TargetEditor::Cursor)
        ->and($scan->rulesFiles->first()->filename)->toBe('CLAUDE.md')
        ->and($user->repositories->pluck('id')->all())->toBe([$repository->id])
        ->and($repository->latestScan->is($scan))->toBeTrue();
});

test('scans transition through statuses and stamp timestamps', function () {
    $scan = Scan::factory()->create();

    $scan->transitionTo(ScanStatus::Fetching);
    expect($scan->started_at)->not->toBeNull()->and($scan->finished_at)->toBeNull();

    $scan->transitionTo(ScanStatus::Complete);
    expect($scan->finished_at)->not->toBeNull()->and($scan->isTerminal())->toBeTrue();

    $failed = Scan::factory()->create();
    $failed->markFailed('Repository too large.');
    expect($failed->fresh()->status)->toBe(ScanStatus::Failed)
        ->and($failed->error_message)->toBe('Repository too large.');
});

test('scans are routed by uuid', function () {
    expect((new Scan)->getRouteKeyName())->toBe('uuid');
});
