<?php

namespace App\Services\Scanning;

use App\Models\Scan;
use App\Scanning\Fetch\ScanWorkspace;

/**
 * Maps a scan to its temp directory: {scan_storage_path}/{uuid}.
 */
final class ScanWorkspaceFactory
{
    public function __construct(private readonly string $basePath) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function workspaceFor(Scan $scan): ScanWorkspace
    {
        return ScanWorkspace::at($this->basePath.'/'.$scan->uuid);
    }

    public function create(Scan $scan): ScanWorkspace
    {
        return ScanWorkspace::create($this->basePath, $scan->uuid);
    }
}
