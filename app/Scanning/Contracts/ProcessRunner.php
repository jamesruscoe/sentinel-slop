<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Data\ProcessResult;
use App\Scanning\Data\ProcessSpec;

/**
 * Runs an analyser as an external process. Today a local process; later a
 * sandboxed one. Analysers never depend on how the process is run.
 */
interface ProcessRunner
{
    public function run(ProcessSpec $spec): ProcessResult;
}
