<?php

use App\Models\Finding;
use App\Models\Scan;
use App\Services\Scanning\CodeRetention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

function codeScan(int $daysOld): Scan
{
    $scan = Scan::factory()->create(['synthesis_payload' => ['system' => 'SYS', 'user' => "Repository: x\nSlop score: 50/100\n\nFindings (most severe first):\n- [high] a.php:3 secret code here", 'model' => 'm']]);
    Finding::factory()->for($scan)->create(['snippet' => 'return "0";']);
    Finding::factory()->for($scan)->create(['snippet' => null]);
    Scan::withoutTimestamps(fn () => $scan->forceFill(['created_at' => Carbon::now()->subDays($daysOld)])->save());

    return $scan;
}

test('purge removes payloads and snippets older than the configured age and leaves newer ones', function () {
    $old = codeScan(31);
    $new = codeScan(29);

    expect((new CodeRetention('purge', 30))->apply())->toBe(['scans' => 1, 'findings' => 1])
        ->and($old->refresh()->synthesis_payload)->toBeNull()
        ->and($old->findings()->whereNotNull('snippet')->count())->toBe(0)
        ->and($old->findings()->count())->toBe(2)
        ->and($new->refresh()->synthesis_payload)->not->toBeNull()
        ->and($new->findings()->whereNotNull('snippet')->count())->toBe(1);
});

test('truncate keeps the payload headers, removes its findings and every snippet, once', function () {
    $old = codeScan(31);
    $retention = new CodeRetention('truncate', 30);

    expect($retention->apply())->toBe(['scans' => 1, 'findings' => 1])
        ->and($old->refresh()->synthesis_payload['user'])->toContain('Slop score: 50/100', '[findings removed by retention policy]')
        ->and($old->synthesis_payload['user'])->not->toContain('secret code here')
        ->and($old->synthesis_payload['system'])->toBe('SYS')
        ->and($old->synthesis_payload['truncated'])->toBeTrue()
        ->and($old->findings()->whereNotNull('snippet')->count())->toBe(0)
        ->and($retention->apply())->toBe(['scans' => 0, 'findings' => 0]);
});

test('retain changes nothing and unknown modes are rejected', function () {
    $old = codeScan(400);

    expect((new CodeRetention('retain', 30))->apply())->toBe(['scans' => 0, 'findings' => 0])
        ->and($old->refresh()->synthesis_payload)->not->toBeNull()
        ->and($old->findings()->whereNotNull('snippet')->count())->toBe(1)
        ->and(fn () => new CodeRetention('keep', 30))->toThrow(InvalidArgumentException::class);
});

test('the prune command is scheduled daily and applies the configured policy', function () {
    config(['sentinel.retention.code' => 'purge', 'sentinel.retention.days' => 1]);
    $old = codeScan(2);

    $this->artisan('sentinel:prune')->assertSuccessful()->expectsOutputToContain('1 synthesis payload(s) and 1 finding snippet(s)');

    expect($old->refresh()->synthesis_payload)->toBeNull()
        ->and(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'sentinel:prune') && $e->expression === '0 0 * * *'))->toBeTrue();
});
