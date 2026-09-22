<?php

use App\Models\Installation;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;

test('users can only view repositories and scans covered by their own installations', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $repository = Repository::factory()->for(Installation::factory()->for($owner))->create();
    $scan = Scan::factory()->for($repository)->create();

    expect($owner->can('view', $repository))->toBeTrue()
        ->and($owner->can('scan', $repository))->toBeTrue()
        ->and($owner->can('view', $scan))->toBeTrue()
        ->and($other->can('view', $repository))->toBeFalse()
        ->and($other->can('scan', $repository))->toBeFalse()
        ->and($other->can('view', $scan))->toBeFalse();
});

test('suspended installations and removed repositories cannot be scanned', function () {
    $owner = User::factory()->create();
    $suspended = Repository::factory()->for(Installation::factory()->suspended()->for($owner))->create();
    $removed = Repository::factory()->removed()->for(Installation::factory()->for($owner))->create();

    expect($owner->can('view', $suspended))->toBeTrue()
        ->and($owner->can('scan', $suspended))->toBeFalse()
        ->and($owner->can('scan', $removed))->toBeFalse();
});

test('the scan broadcast channel is only authorised for the owner', function () {
    // The null/log broadcasters skip channel authorisation, so use the real Reverb driver (auth makes no connection).
    // Channels were registered on the driver active at boot, so register them again on this one.
    config()->set('broadcasting.default', 'reverb');
    require base_path('routes/channels.php');

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $scan = Scan::factory()->for(Repository::factory()->for(Installation::factory()->for($owner)))->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['channel_name' => 'private-scans.'.$scan->uuid, 'socket_id' => '1234.5678'])
        ->assertOk();

    $this->actingAs($other)
        ->post('/broadcasting/auth', ['channel_name' => 'private-scans.'.$scan->uuid, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});
