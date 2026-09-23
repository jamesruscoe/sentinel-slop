<?php

namespace Tests\Feature;

use Tests\TestCase;

class DogControllerTest extends TestCase
{
    public function test_index_renders(): void
    {
        $this->get('/dogs')->assertOk();
    }
}
