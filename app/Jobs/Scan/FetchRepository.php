<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\FetchLimits;
use App\Scanning\Data\RepositoryRef;
use App\Scanning\Fetch\GitHubTreeFetcher;
use App\Scanning\Fetch\ScanWorkspace;
use App\Services\Scanning\ContentSourceResolver;
use App\Services\Scanning\ScanWorkspaceFactory;

class FetchRepository extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Fetching;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $repository = $scan->repository()->with('installation')->firstOrFail();
        $source = app(ContentSourceResolver::class)->forRepository($repository);
        $workspace = $workspaces->create($scan);

        $ref = RepositoryRef::fromFullName($repository->full_name);

        if ($repository->default_branch === null) {
            $repository->forceFill(['default_branch' => $source->getRepository($ref->owner, $ref->name)['default_branch']])->save();
        }

        $ref = RepositoryRef::fromFullName($repository->full_name, $repository->default_branch);

        $result = (new GitHubTreeFetcher($source))->fetch(
            $ref,
            $workspace,
            FetchLimits::fromConfig(config('sentinel')),
            fn (int $done, int $total) => $this->progress($scan, "Downloaded {$done} of {$total} files"),
        );

        $languages = $source->getLanguages($ref->owner, $ref->name);

        $scan->forceFill(['commit_sha' => $result->commitSha])->save();
        $workspace->writeArtifact('fetch', $result->toArray() + ['languages' => $languages]);
    }
}
