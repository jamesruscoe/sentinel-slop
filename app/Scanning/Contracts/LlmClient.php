<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Synthesis\LlmResponse;
use App\Scanning\Synthesis\PromptSynthesiser;

/**
 * The one LLM call synthesis makes. Implementations return the decoded plan:
 * {phases: [{phase: int, title: string, body: string}], rules: {summary: string, sections: [{heading: string, items: string[]}]}}
 *
 * Implementations MUST throw SynthesisException when the reply was cut off
 * (finish reason "length"), ended for any reason other than a normal stop,
 * or could not be parsed. Returning a partial plan is never acceptable.
 *
 * @see PromptSynthesiser for how the result is validated.
 */
interface LlmClient
{
    public function plan(string $systemPrompt, string $userPrompt, string $model): LlmResponse;
}
