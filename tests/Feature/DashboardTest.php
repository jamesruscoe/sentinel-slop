<?php

use App\Models\Installation;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;

test('the landing page states the safety guarantees', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('only read, never run')
        ->assertSee('deleted after every scan')
        ->assertSee('sent to an AI provider');
});

test('the dashboard lists only the current user\'s active repositories with their latest score', function () {
    $user = User::factory()->create();
    $installation = Installation::factory()->for($user)->create();
    $scanned = Repository::factory()->for($installation)->create(['full_name' => 'me/scanned']);
    Scan::factory()->for($scanned)->complete(score: 73)->create();
    Scan::factory()->for($scanned)->failed()->create();
    Repository::factory()->for($installation)->create(['full_name' => 'me/fresh']);
    Repository::factory()->for($installation)->removed()->create(['full_name' => 'me/removed']);
    Repository::factory()->create(['full_name' => 'someone/else']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['me/fresh', 'Not scanned yet', 'me/scanned', 'Score', '73'])
        ->assertDontSee('me/removed')
        ->assertDontSee('someone/else');
});
