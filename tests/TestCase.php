<?php

namespace Tests;

use App\Scanning\Fetch\ScanWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var list<ScanWorkspace> */
    public array $workspacesToDelete = [];

    protected function tearDown(): void
    {
        foreach ($this->workspacesToDelete as $workspace) {
            $workspace->delete();
        }
        $this->workspacesToDelete = [];

        parent::tearDown();
    }
}
