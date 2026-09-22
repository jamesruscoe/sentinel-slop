<?php

declare(strict_types=1);

namespace App\Scanning\Data;

/**
 * An external process to run. Always an argv array, never a shell string.
 */
final class ProcessSpec
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Full environment for the process (no inheritance of app secrets).
     */
    public function __construct(
        public readonly array $command,
        public readonly string $workingDirectory,
        public readonly int $timeoutSeconds,
        public readonly array $env = [],
    ) {}
}
