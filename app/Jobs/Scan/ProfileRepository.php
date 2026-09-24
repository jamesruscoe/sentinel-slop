<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Profile\AbsenceChecks;
use App\Scanning\Profile\ProfileConfig;
use App\Scanning\Profile\RepositoryProfiler;
use App\Services\Scanning\ScanWorkspaceFactory;
use RuntimeException;

/**
 * Computes the structural profile (no analyser involved) and the absence
 * findings derived from it. Writes work/profile plus work/findings/profile,
 * and removes jscpd pair findings that a reported duplication cluster
 * already covers so the cluster is counted once.
 */
class ProfileRepository extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Profiling;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $stack = Stack::fromArray($workspace->readArtifact('stack') ?? throw new RuntimeException('The stack artifact is missing.'));
        $config = ProfileConfig::fromArray((array) config('sentinel.profile', []));

        $jscpdArtifact = $workspace->readArtifact('findings/jscpd');
        $jscpd = $jscpdArtifact !== null ? FindingCollection::fromArray(array_values((array) ($jscpdArtifact['findings'] ?? []))) : null;

        $pintArtifact = $workspace->readArtifact('findings/pint');
        $style = $pintArtifact !== null ? FindingCollection::fromArray(array_values((array) ($pintArtifact['findings'] ?? []))) : null;

        $profile = (new RepositoryProfiler($config))->profile($workspace->repoPath(), $stack, $jscpd, $style);
        $findings = (new AbsenceChecks($config))->run($profile);

        $superseded = array_fill_keys((array) ($profile->section('duplication')['superseded_pairs'] ?? []), true);
        if ($jscpd !== null && $superseded !== []) {
            $kept = $jscpd->filter(fn (Finding $f) => ! isset($superseded[$f->filePath.':'.($f->line ?? 0)]));
            $workspace->writeArtifact('findings/jscpd', ['tool' => 'jscpd', 'findings' => $kept->toArray(), 'superseded_by_clusters' => count($superseded)]);
        }

        $workspace->writeArtifact('profile', $profile->toArray());
        $workspace->writeArtifact('findings/profile', ['tool' => AbsenceChecks::TOOL, 'findings' => $findings->toArray()]);
        $scan->forceFill(['profile' => $profile->toArray()])->save();
    }
}
