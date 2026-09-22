<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * A tool ran but crashed or produced unreadable output. The detail is for
 * logs; the user sees a generic message.
 */
class AnalyserFailedException extends ScanException
{
    public function userMessage(): string
    {
        return 'One of the analysers failed while scanning this repository. Please try again later.';
    }
}
