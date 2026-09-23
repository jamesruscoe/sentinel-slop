<?php

namespace Tests\Unit\Models;

use App\Models\Owner;
use Tests\TestCase;

class OwnerTest extends TestCase
{
    public function test_it_is_a_model(): void
    {
        $this->assertInstanceOf(Owner::class, new Owner);
    }
}
