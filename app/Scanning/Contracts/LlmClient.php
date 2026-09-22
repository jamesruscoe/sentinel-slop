<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

use App\Scanning\Synthesis\PromptSynthesiser;

/**
 * The one LLM call synthesis makes. Implementations return the decoded plan:
 * {phases: [{phase: int, title: string, body: string}], rules: {summary: string, sections: [{heading: string, items: string[]}]}}
 *
 * @see PromptSynthesiser for how the result is validated.
 */
interface LlmClient
{
    /**
     * @return array<string, mixed>
     */
    public function plan(string $systemPrompt, string $userPrompt, string $model): array;
}
