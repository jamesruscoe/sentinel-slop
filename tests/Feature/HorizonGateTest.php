<?php

use App\Models\User;

test('only configured admins can open Horizon', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
});
