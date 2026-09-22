<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * An analyser binary is missing or unusable. Not user-facing: this is an
 * operator problem, so the scan fails with a generic message and the detail
 * goes to the log.
 */
class AnalyserUnavailableException extends ScanException
{
    public function userMessage(): string
    {
        return 'A required analyser is not installed on the scanning server. The operator has been notified.';
    }
}
