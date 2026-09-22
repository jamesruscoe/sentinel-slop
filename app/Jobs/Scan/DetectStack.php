<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Detect\StackDetector;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ScanWorkspaceFactory;
use RuntimeException;

class DetectStack extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Detecting;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $fetch = $workspace->readArtifact('fetch') ?? throw new RuntimeException('The fetch artifact is missing.');

        /** @var array<string, int> $languages */
        $languages = array_map('intval', (array) ($fetch['languages'] ?? []));
        /** @var list<string> $treePaths */
        $treePaths = array_values(array_map('strval', (array) ($fetch['tree_paths'] ?? [])));

        $stack = (new StackDetector)->detect($workspace->repoPath(), $languages, $treePaths);

        $scan->forceFill(['detected_stack' => $stack->toArray()])->save();
        $workspace->writeArtifact('stack', $stack->toArray());
    }
}
