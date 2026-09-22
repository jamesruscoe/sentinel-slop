<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Finding;
use App\Models\Scan;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Normalise\FindingNormaliser;
use App\Services\Scanning\ScanWorkspaceFactory;

/**
 * Gathers preflight findings plus every `work/findings/*.json` artifact the
 * analysers and heuristics wrote, normalises them and stores them.
 */
class NormaliseFindings extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Normalising;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $raw = new FindingCollection;

        $preflight = $workspace->readArtifact('preflight');
        if ($preflight !== null) {
            $raw = $raw->merge(FindingCollection::fromArray(array_values((array) ($preflight['findings'] ?? []))));
        }

        foreach ($workspace->listArtifacts('findings') as $artifact) {
            $data = $workspace->readArtifact($artifact) ?? [];
            $raw = $raw->merge(FindingCollection::fromArray(array_values((array) ($data['findings'] ?? []))));
        }

        $normaliser = new FindingNormaliser(maxSnippetLines: (int) config('sentinel.synthesis.max_snippet_lines', 6));
        $findings = $normaliser->normalise($raw, $workspace->repoPath());

        $scan->findings()->delete();

        $now = now();
        foreach (array_chunk($findings->toArray(), 500) as $chunk) {
            Finding::query()->insert(array_map(
                fn (array $row) => $row + ['scan_id' => $scan->id, 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));
        }

        $workspace->writeArtifact('findings-normalised', ['findings' => $findings->toArray()]);
    }
}
