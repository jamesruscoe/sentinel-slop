<?php

namespace App\Services\Scanning;

use App\Models\Repository;
use App\Scanning\Contracts\GitHubContentSource;

/**
 * Produces a content source authenticated for one repository. The real
 * implementation mints a short-lived installation token; tests bind a fake.
 */
interface ContentSourceResolver
{
    public function forRepository(Repository $repository): GitHubContentSource;
}
