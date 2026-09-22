<?php

namespace App\Services\Scanning;

use App\Scanning\Contracts\ProcessRunner;
use App\Scanning\Data\ProcessResult;
use App\Scanning\Data\ProcessSpec;
use App\Scanning\Process\EnvironmentAllowlist;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Runs analysers through Laravel's Process facade: argv arrays only (never a
 * shell string), a hard timeout, a controlled working directory and a
 * scrubbed environment.
 */
final class LaravelProcessRunner implements ProcessRunner
{
    public function run(ProcessSpec $spec): ProcessResult
    {
        $pending = Process::timeout($spec->timeoutSeconds)
            ->path($spec->workingDirectory)
            ->env(EnvironmentAllowlist::build($spec->env))
            ->command($spec->command);

        try {
            $result = $pending->run();
        } catch (ProcessTimedOutException $e) {
            return new ProcessResult($e->result->exitCode() ?? -1, $e->result->output(), $e->result->errorOutput(), timedOut: true);
        }

        return new ProcessResult($result->exitCode() ?? -1, $result->output(), $result->errorOutput());
    }
}
