<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * An analyser ran past its timeout. Recorded, never fatal: the scan goes on
 * without that tool and says so in its coverage.
 */
final class AnalyserTimedOutException extends AnalyserFailedException
{
    public function __construct(string $tool, public readonly int $seconds)
    {
        parent::__construct("{$tool} exceeded the {$seconds}s timeout.");
    }
}
