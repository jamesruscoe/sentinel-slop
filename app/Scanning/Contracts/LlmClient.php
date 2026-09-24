<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Synthesis\LlmResponse;
use App\Scanning\Synthesis\PromptSynthesiser;

/**
 * The LLM calls synthesis makes. `review()` returns the assessment, the
 * phase outline and the rules file:
 *   {assessment: {...}, phases: [{phase, title, goal, addresses[], finding_ids[]}], rules: {...}}
 * `phase()` returns the body of one phase: {body: string}.
 *
 * Implementations MUST throw SynthesisException when a reply was cut off
 * (finish reason "length"), ended for any reason other than a normal stop,
 * or could not be parsed. Returning a partial result is never acceptable.
 *
 * @see PromptSynthesiser for how the results are validated and assembled.
 */
interface LlmClient
{
    public function review(string $systemPrompt, string $userPrompt, string $model): LlmResponse;

    public function phase(string $systemPrompt, string $userPrompt, string $model): LlmResponse;
}
