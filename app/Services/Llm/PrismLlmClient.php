<?php

namespace App\Services\Llm;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Exceptions\SynthesisException;
use App\Scanning\Synthesis\LlmResponse;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Structured-output call through Prism. Provider comes from config; the
 * model is chosen per scan. Only the redacted payload built by the
 * synthesiser is ever sent. Prism decodes native structured output with a
 * plain json_decode, so a truncated reply silently becomes an empty array:
 * the finish reason is checked before anything else.
 */
final class PrismLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $provider,
        private readonly int $maxOutputTokens = 32000,
        private readonly int $timeoutSeconds = 600,
    ) {}

    public function plan(string $systemPrompt, string $userPrompt, string $model): LlmResponse
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
            // No previous: a Guzzle/Prism exception chain can carry the request object (and its headers).
            throw new SynthesisException('LLM request failed: '.LlmSecretScrubber::scrub($e->getMessage()));
        }

        $finish = $response->finishReason;
        $usage = sprintf('%d output tokens of %d allowed, %d input tokens', $response->usage->completionTokens, $this->maxOutputTokens, $response->usage->promptTokens);

        if ($finish === FinishReason::Length) {
            throw new SynthesisException("The model's reply was cut off by the output limit ({$usage}). Raise SENTINEL_LLM_MAX_OUTPUT_TOKENS or lower SENTINEL_LLM_MAX_FINDINGS.");
        }

        if ($finish !== FinishReason::Stop) {
            throw new SynthesisException("The model stopped for an unexpected reason: {$finish->name} ({$usage}).");
        }

        if (! is_array($response->structured) || $response->structured === []) {
            throw new SynthesisException(sprintf('The model returned no parseable structured output (finish reason %s, %s, %d characters of text).', $finish->name, $usage, strlen($response->text)));
        }

        return new LlmResponse($response->structured, strtolower($finish->name), $response->usage->promptTokens, $response->usage->completionTokens);
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
