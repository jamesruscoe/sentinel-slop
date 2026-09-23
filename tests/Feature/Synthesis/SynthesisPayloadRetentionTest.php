<?php

use App\Models\Scan;
use App\Services\Scanning\SynthesisPayloadRetention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

function payloadScan(int $daysOld): Scan
{
    $scan = Scan::factory()->create(['synthesis_payload' => ['system' => 'SYS', 'user' => "Repository: x\nSlop score: 50/100\n\nFindings (most severe first):\n- [high] a.php:3 secret code here", 'model' => 'm']]);
    Scan::withoutTimestamps(fn () => $scan->forceFill(['created_at' => Carbon::now()->subDays($daysOld)])->save());

    return $scan;
}

test('purge nulls payloads older than the configured age and leaves newer ones', function () {
    $old = payloadScan(31);
    $new = payloadScan(29);

    expect((new SynthesisPayloadRetention('purge', 30))->apply())->toBe(1)
        ->and($old->refresh()->synthesis_payload)->toBeNull()
        ->and($new->refresh()->synthesis_payload)->not->toBeNull();
});

test('truncate keeps the headers and removes the findings section once', function () {
    $old = payloadScan(31);
    $retention = new SynthesisPayloadRetention('truncate', 30);

    expect($retention->apply())->toBe(1)
        ->and($old->refresh()->synthesis_payload['user'])->toContain('Slop score: 50/100', '[findings removed by retention policy]')
        ->and($old->synthesis_payload['user'])->not->toContain('secret code here')
        ->and($old->synthesis_payload['system'])->toBe('SYS')
        ->and($old->synthesis_payload['truncated'])->toBeTrue()
        ->and($retention->apply())->toBe(0);
});

test('retain changes nothing and unknown modes are rejected', function () {
    $old = payloadScan(400);

    expect((new SynthesisPayloadRetention('retain', 30))->apply())->toBe(0)
        ->and($old->refresh()->synthesis_payload)->not->toBeNull()
        ->and(fn () => new SynthesisPayloadRetention('keep', 30))->toThrow(InvalidArgumentException::class);
});

test('the prune command is scheduled daily and applies the configured policy', function () {
    config(['sentinel.retention.synthesis_payload' => 'purge', 'sentinel.retention.synthesis_payload_days' => 1]);
    $old = payloadScan(2);

    $this->artisan('sentinel:prune')->assertSuccessful();

    expect($old->refresh()->synthesis_payload)->toBeNull()
        ->and(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'sentinel:prune') && $e->expression === '0 0 * * *'))->toBeTrue();
});
