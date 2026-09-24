<?php

use App\Enums\ScanStatus;
use App\Livewire\ScanShow;
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
use App\Services\Scanning\ScanPipeline;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

function ownedRepository(User $user): Repository
{
    return Repository::factory()->for(Installation::factory()->for($user))->create();
}

test('scan pages require login', function () {
    $scan = Scan::factory()->create();

    $this->get(route('scans.index'))->assertRedirect(route('login'));
    $this->get(route('scans.show', $scan))->assertRedirect(route('login'));
    $this->post(route('repositories.scans.store', $scan->repository))->assertRedirect(route('login'));
});

test('users cannot see or scan repositories they do not own', function () {
    $user = User::factory()->create();
    $scan = Scan::factory()->complete()->create();

    $this->actingAs($user)->get(route('scans.show', $scan))->assertForbidden();
    $this->actingAs($user)->get(route('repositories.scans.index', $scan->repository))->assertForbidden();
    $this->actingAs($user)->get(route('scans.rules', [$scan, 'claude_code']))->assertForbidden();
    $this->actingAs($user)->post(route('repositories.scans.store', $scan->repository))->assertForbidden();
});

test('starting a scan queues the pipeline and redirects to the live page', function () {
    Bus::fake();
    $user = User::factory()->create();
    $repository = ownedRepository($user);

    $response = $this->actingAs($user)->post(route('repositories.scans.store', $repository), ['model' => config('sentinel.synthesis.model')]);

    $scan = $repository->scans()->sole();
    $response->assertRedirect(route('scans.show', $scan));
    expect($scan->status)->toBe(ScanStatus::Queued)->and($scan->user_id)->toBe($user->id);
    Bus::assertChained(array_map(fn (object $job) => $job::class, ScanPipeline::jobs($scan)));
});

test('an unknown model is rejected and a running scan is not duplicated', function () {
    Bus::fake();
    $user = User::factory()->create();
    $repository = ownedRepository($user);

    $this->actingAs($user)->from(route('dashboard'))->post(route('repositories.scans.store', $repository), ['model' => 'gpt-9'])
        ->assertRedirect(route('dashboard'))->assertSessionHasErrors('model');

    $running = Scan::factory()->for($repository)->create(['status' => ScanStatus::Analysing]);
    $this->actingAs($user)->post(route('repositories.scans.store', $repository))
        ->assertRedirect(route('scans.show', $running))->assertSessionHas('error');
    expect($repository->scans()->count())->toBe(1);
});

test('the live page shows progress for an active scan and refreshes on broadcast events', function () {
    $user = User::factory()->create();
    $scan = Scan::factory()->for(ownedRepository($user))->create(['status' => ScanStatus::Analysing]);

    $component = Livewire::actingAs($user)->test(ScanShow::class, ['scan' => $scan])
        ->assertSee('Running analysers')
        ->assertSee('wire:poll', false)
        ->assertDontSee('Fix-it prompts');

    $scan->forceFill(['status' => ScanStatus::Complete, 'slop_score' => 82, 'lines_of_code' => 900])->save();
    $component->call('refreshScan')->assertSee('Fix-it prompts')->assertSee('82');
});

test('the results page shows score, stack, prompts, rules, findings and the payload', function () {
    $user = User::factory()->create();
    $scan = Scan::factory()->for(ownedRepository($user))->complete(score: 64)->create([
        'detected_stack' => ['languages' => ['PHP' => 900, 'Blade' => 100], 'frameworks' => ['laravel'], 'versions' => ['laravel' => '^12.0', 'php' => '^8.3'], 'tooling' => ['pint']],
        'synthesis_payload' => ['system' => 'SYSTEM TEXT', 'user' => 'USER TEXT', 'model' => 'claude-sonnet-5', 'usage' => ['finish_reason' => 'stop', 'input_tokens' => 4000, 'output_tokens' => 3000]],
        'suppression_count' => 2, 'suppression_density' => 2.27,
        'assessment' => [
            'summary' => "The service layer carries the logic and the controllers stay thin.\n\nNothing in app/Services logs, so handled failures leave no trace.",
            'strengths' => ['No secrets found'],
            'structural_problems' => [['title' => 'No logging in app/Services', 'evidence' => '18 files, none logs', 'impact' => 'Failures are invisible']],
            'recommended_refactors' => [['title' => 'Introduce an audit logger', 'rationale' => 'One way to log', 'scope' => 'app/Services', 'effort' => 'small']],
        ],
        'profile' => ['summary' => ['files' => 12, 'source_files' => 10, 'test_files' => 2, 'code_lines' => 500, 'comment_lines' => 20, 'comment_density' => 4.0, 'families' => ['php' => 10], 'assessed_families' => ['php'], 'unassessed_families' => [], 'max_depth' => 3, 'frameworks' => ['laravel']], 'areas' => [], 'observations' => ['PROFILE OBSERVATION TEXT']],
    ]);
    foreach ([1, 2, 3, 4, 5] as $phase) {
        Prompt::factory()->for($scan)->create(['phase' => $phase, 'title' => "Claude phase {$phase}", 'body' => "Claude body {$phase}"]);
        Prompt::factory()->for($scan)->create(['phase' => $phase, 'target_editor' => TargetEditor::Cursor, 'title' => "Cursor phase {$phase}", 'body' => "Cursor body {$phase}"]);
    }
    RulesFile::factory()->for($scan)->create(['body' => '# Claude rules']);
    Finding::factory()->for($scan)->create(['severity' => Severity::High, 'category' => FindingCategory::TypeSafety, 'file_path' => 'app/A.php', 'line' => 3, 'symbol' => 'App\A::total()', 'message' => 'Undefined method total']);
    Finding::factory()->for($scan)->create(['severity' => Severity::Low, 'category' => FindingCategory::Style, 'file_path' => 'app/B.php', 'line' => null, 'message' => 'Style drift']);

    $page = $this->actingAs($user)->get(route('scans.show', $scan))->assertOk();
    $page->assertSee('64')->assertSee('laravel 12')->assertSee('PHP 8.3')
        ->assertSee('Assessment')->assertSee('The service layer carries the logic')->assertSee('Nothing in app/Services logs')
        ->assertSee('No logging in app/Services')->assertSee('18 files, none logs')->assertSee('Introduce an audit logger')->assertSee('No secrets found')
        ->assertSee('Repository profile')->assertDontSee('PROFILE OBSERVATION TEXT')
        ->assertSee('Claude phase 1')->assertSee('Claude body 5')->assertDontSee('Cursor body 1')
        ->assertSee('CLAUDE.md')->assertSee('app/A.php:3 in App\A::total()')->assertSee('Undefined method total')
        ->assertSee('4,000 tokens in')->assertDontSee('USER TEXT');

    Livewire::actingAs($user)->test(ScanShow::class, ['scan' => $scan])
        ->set('editor', 'cursor')->assertSee('Cursor body 1')->assertDontSee('Claude body 1')
        ->set('severityFilter', 'low')->assertSee('Style drift')->assertDontSee('Undefined method total')
        ->set('severityFilter', '')->set('categoryFilter', 'type_safety')->assertSee('Undefined method total')->assertDontSee('Style drift')
        ->set('showPayload', true)->assertSee('USER TEXT')->assertSee('SYSTEM TEXT');
});

test('rules files and prompt bundles download for the chosen editor', function () {
    $user = User::factory()->create();
    $scan = Scan::factory()->for(ownedRepository($user))->complete()->create();
    RulesFile::factory()->for($scan)->create(['body' => '# Rules body']);
    Prompt::factory()->for($scan)->create(['phase' => 1, 'body' => 'P1']);
    Prompt::factory()->for($scan)->create(['phase' => 2, 'body' => 'P2']);

    $this->actingAs($user)->get(route('scans.rules', [$scan, 'claude_code']))
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="CLAUDE.md"')->assertSee('# Rules body');
    $this->actingAs($user)->get(route('scans.prompts', [$scan, 'claude_code']))
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="sentinel-slop-prompts-claude-code.md"')->assertSeeInOrder(['P1', '---', 'P2']);
    $this->actingAs($user)->get(route('scans.rules', [$scan, 'cursor']))->assertNotFound();
    $this->actingAs($user)->get(route('scans.rules', [$scan, 'vim']))->assertNotFound();
});

test('a failed scan shows its user-safe message and offers a re-scan', function () {
    $user = User::factory()->create();
    $scan = Scan::factory()->for(ownedRepository($user))->failed('The repository is too large to scan.')->create();

    $this->actingAs($user)->get(route('scans.show', $scan))->assertOk()
        ->assertSee('This scan failed')->assertSee('The repository is too large to scan.')->assertSee('Scan again');
});

test('history pages list the user\'s scans newest first, per repository and overall', function () {
    $user = User::factory()->create();
    $mine = ownedRepository($user);
    $other = ownedRepository($user);
    $older = Scan::factory()->for($mine)->complete(score: 40)->create();
    $newer = Scan::factory()->for($mine)->failed()->create();
    Scan::factory()->for($other)->complete(score: 91)->create();
    $foreign = Scan::factory()->complete(score: 12)->create();

    $this->actingAs($user)->get(route('scans.index'))->assertOk()
        ->assertSee($mine->full_name)->assertSee($other->full_name)->assertSee('91')->assertDontSee($foreign->repository->full_name);
    $this->actingAs($user)->get(route('repositories.scans.index', $mine))->assertOk()
        ->assertSeeInOrder([route('scans.show', $newer), route('scans.show', $older)])->assertDontSee($other->full_name);
});

test('the dashboard links each repository to its latest scan and offers a scan button', function () {
    $user = User::factory()->create();
    $repository = ownedRepository($user);
    $scan = Scan::factory()->for($repository)->complete(score: 77)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee(route('scans.show', $scan))->assertSee('77')->assertSee(route('repositories.scans.store', $repository));
});
