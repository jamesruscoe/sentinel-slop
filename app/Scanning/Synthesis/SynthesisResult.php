<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Enums\TargetEditor;

/**
 * @phpstan-type Phase array{phase: int, title: string, goal: string, body: string, addresses: list<string>}
 * @phpstan-type Assessment array{summary: string, strengths: list<string>, structural_problems: list<array{title: string, evidence: string, impact: string}>, recommended_refactors: list<array{title: string, rationale: string, scope: string, effort: string}>}
 */
final class SynthesisResult
{
    /**
     * @param  Assessment  $assessment  The review: prose summary, what is good, the structural problems, the refactors.
     * @param  list<Phase>  $phases  Editor-neutral plan from the LLM, three to six phases ordered by impact.
     * @param  array<string, list<array{phase: int, title: string, body: string}>>  $prompts  editor value => rendered prompts
     * @param  array<string, array{filename: string, body: string}>  $rulesFiles  editor value => rendered rules file
     * @param  array{included: int, omitted: int, aggregated: int, estimated_tokens: int, profile_tokens: int}  $budget
     * @param  array{system: string, user: string, model: string, usage?: array{finish_reason: string, input_tokens: int, output_tokens: int}}  $payload  Exactly what was sent to the LLM, plus usage.
     */
    public function __construct(
        public readonly array $assessment,
        public readonly array $phases,
        public readonly array $prompts,
        public readonly array $rulesFiles,
        public readonly array $budget,
        public readonly array $payload = ['system' => '', 'user' => '', 'model' => ''],
    ) {}

    /**
     * @return list<array{phase: int, title: string, body: string}>
     */
    public function promptsFor(TargetEditor $editor): array
    {
        return $this->prompts[$editor->value] ?? [];
    }

    /**
     * @return array{filename: string, body: string}|null
     */
    public function rulesFileFor(TargetEditor $editor): ?array
    {
        return $this->rulesFiles[$editor->value] ?? null;
    }
}
