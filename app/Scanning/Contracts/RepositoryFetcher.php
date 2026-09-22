<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Data\FetchLimits;
use App\Scanning\Data\FetchResult;
use App\Scanning\Data\RepositoryRef;
use App\Scanning\Fetch\ScanWorkspace;

interface RepositoryFetcher
{
    /**
     * Download the repository's regular files into the workspace's repo directory.
     *
     * @param  (callable(int $filesDone, int $filesTotal): void)|null  $onProgress
     */
    public function fetch(RepositoryRef $repository, ScanWorkspace $workspace, FetchLimits $limits, ?callable $onProgress = null): FetchResult;
}
