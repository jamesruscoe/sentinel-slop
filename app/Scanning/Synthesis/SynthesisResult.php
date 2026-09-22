<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Enums\TargetEditor;

final class SynthesisResult
{
    /**
     * @param  list<array{phase: int, title: string, body: string}>  $phases  Editor-neutral plan from the LLM.
     * @param  array<string, list<array{phase: int, title: string, body: string}>>  $prompts  editor value => rendered prompts
     * @param  array<string, array{filename: string, body: string}>  $rulesFiles  editor value => rendered rules file
     * @param  array{included: int, omitted: int, estimated_tokens: int}  $budget
     * @param  array{system: string, user: string, model: string}  $payload  Exactly what was sent to the LLM.
     */
    public function __construct(
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
