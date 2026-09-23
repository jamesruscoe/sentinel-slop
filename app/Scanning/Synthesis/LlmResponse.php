<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

/**
 * A complete, parsed structured response. Clients must never construct one
 * from a truncated or unparseable reply: they throw SynthesisException instead.
 */
final class LlmResponse
{
    /**
     * @param  array<string, mixed>  $plan
     */
    public function __construct(
        public readonly array $plan,
        public readonly string $finishReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    /**
     * @return array{finish_reason: string, input_tokens: int, output_tokens: int}
     */
    public function usage(): array
    {
        return ['finish_reason' => $this->finishReason, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }
}
