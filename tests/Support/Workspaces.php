<?php

namespace Tests\Support;

use App\Scanning\Fetch\ScanWorkspace;

/**
 * Tracks temporary workspaces created by test helpers so TestCase can delete
 * them after each test.
 */
final class Workspaces
{
    /** @var list<ScanWorkspace> */
    private static array $open = [];

    public static function register(ScanWorkspace $workspace): ScanWorkspace
    {
        self::$open[] = $workspace;

        return $workspace;
    }

    public static function cleanup(): void
    {
        foreach (self::$open as $workspace) {
            $workspace->delete();
        }

        self::$open = [];
    }
}
