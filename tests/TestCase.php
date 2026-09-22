<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Workspaces;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Workspaces::cleanup();

        parent::tearDown();
    }
}
