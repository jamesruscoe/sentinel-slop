<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * Prompt generation failed. Non-fatal for the scan: findings and score are
 * kept and the user is told prompts could not be generated.
 */
class SynthesisException extends ScanException
{
    public function userMessage(): string
    {
        return 'Prompt generation failed. Findings and score are available; try re-running the scan later.';
    }
}
