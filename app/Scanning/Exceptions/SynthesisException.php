<?php

declare(strict_types=1);

namespace App\Scanning\Exceptions;

/**
 * Prompt generation failed. Non-fatal for the scan: findings and score are
 * kept and the user is told prompts could not be generated.
 */
class SynthesisException extends ScanException
{
    /** @var array{system: string, user: string, model: string}|null What was sent before the failure, for inspection. */
    public ?array $payload = null;

    /**
     * @param  array{system: string, user: string, model: string}  $payload
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function userMessage(): string
    {
        return 'Prompt generation failed. Findings and score are available; try re-running the scan later.';
    }
}
