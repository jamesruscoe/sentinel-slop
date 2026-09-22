<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

use RuntimeException;

/**
 * Base for failures whose message is safe to show to the user.
 */
class ScanException extends RuntimeException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
