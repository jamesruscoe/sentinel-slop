<?php

namespace App\Services\Llm;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Exceptions\SynthesisException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Structured-output call through Prism. Provider comes from config; the
 * model is chosen per scan. Only the redacted payload built by the
 * synthesiser is ever sent.
 */
final class PrismLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $provider,
        private readonly int $maxOutputTokens = 8000,
        private readonly int $timeoutSeconds = 180,
    ) {}

    public function plan(string $systemPrompt, string $userPrompt, string $model): array
    {
        try {
            $response = Prism::structured()
                ->using($this->provider, $model)
                ->withSchema(self::schema())
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withMaxTokens($this->maxOutputTokens)
                ->withClientOptions(['timeout' => $this->timeoutSeconds])
                ->asStructured();
        } catch (Throwable $e) {
            throw new SynthesisException('LLM request failed: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($response->structured) || $response->structured === []) {
            throw new SynthesisException('The model returned no structured output.');
        }

        return $response->structured;
    }

    public static function schema(): ObjectSchema
    {
        $phase = new ObjectSchema('phase', 'One fix-it phase', [
            new NumberSchema('phase', 'Phase number, 1 to 5'),
            new StringSchema('title', 'Short phase title'),
            new StringSchema('body', 'The complete prompt body for this phase, in Markdown'),
        ], ['phase', 'title', 'body']);

        $section = new ObjectSchema('section', 'A rules-file section', [
            new StringSchema('heading', 'Section heading'),
            new ArraySchema('items', 'Short imperative rules', new StringSchema('item', 'One rule')),
        ], ['heading', 'items']);

        $rules = new ObjectSchema('rules', 'Content for the project rules file', [
            new StringSchema('summary', 'One-paragraph summary of the project standards'),
            new ArraySchema('sections', 'Sections of rules', $section),
        ], ['summary', 'sections']);

        return new ObjectSchema('plan', 'Phased fix-it plan and rules', [
            new ArraySchema('phases', 'Exactly five phases in order', $phase),
            $rules,
        ], ['phases', 'rules']);
    }
}
