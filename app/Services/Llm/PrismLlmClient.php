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
 * Structured-output calls through Prism. Provider comes from config; the
 * model is chosen per scan. Only the redacted payload built by the
 * synthesiser is ever sent. Prism decodes native structured output with a
 * plain json_decode, so a truncated reply silently becomes an empty array:
 * the finish reason is checked before anything else.
 */
final class PrismLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $provider,
        private readonly int $maxOutputTokens = 16000,
        private readonly int $timeoutSeconds = 600,
        private readonly int $phaseMaxOutputTokens = 6000,
    ) {}

    public function review(string $systemPrompt, string $userPrompt, string $model): LlmResponse
    {
        return $this->call(self::reviewSchema(), $systemPrompt, $userPrompt, $model, $this->maxOutputTokens, 'review');
    }

    public function phase(string $systemPrompt, string $userPrompt, string $model): LlmResponse
    {
        return $this->call(self::phaseSchema(), $systemPrompt, $userPrompt, $model, $this->phaseMaxOutputTokens, 'phase');
    }

    private function call(ObjectSchema $schema, string $systemPrompt, string $userPrompt, string $model, int $maxTokens, string $stage): LlmResponse
    {
        try {
            $response = Prism::structured()
                ->using($this->provider, $model)
                ->withSchema($schema)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withMaxTokens($maxTokens)
                ->withClientOptions(['timeout' => $this->timeoutSeconds])
                ->asStructured();
        } catch (Throwable $e) {
            // No previous: a Guzzle/Prism exception chain can carry the request object (and its headers).
            throw new SynthesisException("LLM {$stage} request failed: ".LlmSecretScrubber::scrub($e->getMessage()));
        }

        $finish = $response->finishReason;
        $usage = sprintf('%d output tokens of %d allowed, %d input tokens', $response->usage->completionTokens, $maxTokens, $response->usage->promptTokens);

        if ($finish === FinishReason::Length) {
            throw new SynthesisException("The model's {$stage} reply was cut off by the output limit ({$usage}). Raise the output allowance for this stage or lower SENTINEL_LLM_MAX_FINDINGS.");
        }

        if ($finish !== FinishReason::Stop) {
            throw new SynthesisException("The model stopped the {$stage} reply for an unexpected reason: {$finish->name} ({$usage}).");
        }

        if (! is_array($response->structured) || $response->structured === []) {
            throw new SynthesisException(sprintf('The model returned no parseable structured output for the %s (finish reason %s, %s, %d characters of text).', $stage, $finish->name, $usage, strlen($response->text)));
        }

        return new LlmResponse($response->structured, strtolower($finish->name), $response->usage->promptTokens, $response->usage->completionTokens);
    }

    public static function reviewSchema(): ObjectSchema
    {
        $problem = new ObjectSchema('problem', 'One structural problem', [
            new StringSchema('title', 'Short name of the problem'),
            new StringSchema('evidence', 'The profile facts and findings that show it, with the paths and counts given'),
            new StringSchema('impact', 'What it costs the team in maintainability'),
        ], ['title', 'evidence', 'impact']);

        $refactor = new ObjectSchema('refactor', 'One recommended refactor', [
            new StringSchema('title', 'Short name of the refactor'),
            new StringSchema('rationale', 'Why it is worth doing, tied to the evidence'),
            new StringSchema('scope', 'Which areas or files it touches, using only paths that were given'),
            new StringSchema('effort', 'small, medium or large'),
        ], ['title', 'rationale', 'scope', 'effort']);

        $assessment = new ObjectSchema('assessment', 'The review of the codebase', [
            new StringSchema('summary', 'Two or three paragraphs of plain prose: the state of the codebase, what is working, and the two or three structural changes that would most improve maintainability'),
            new ArraySchema('strengths', 'What is genuinely good, including categories verified clean', new StringSchema('strength', 'One strength')),
            new ArraySchema('structural_problems', 'Specific structural problems, most important first', $problem),
            new ArraySchema('recommended_refactors', 'Concrete refactors, most valuable first', $refactor),
        ], ['summary', 'strengths', 'structural_problems', 'recommended_refactors']);

        $phase = new ObjectSchema('phase', 'One phase of the plan: its outline, not its body', [
            new NumberSchema('phase', 'Position in the plan, 1 first, in the fixed order: correctness, observability, structure, style'),
            new StringSchema('title', 'A real title naming what this phase does for this repository'),
            new StringSchema('goal', 'One sentence: what is true when the phase is done'),
            new ArraySchema('addresses', 'The problem titles or profile facts this phase addresses', new StringSchema('item', 'One reference')),
            new ArraySchema('finding_ids', 'The ids ([F1], [F2], ...) of every finding line this phase should act on; each id in at most one phase', new StringSchema('id', 'A finding id such as F12')),
        ], ['phase', 'title', 'goal', 'addresses', 'finding_ids']);

        $section = new ObjectSchema('section', 'A rules-file section', [
            new StringSchema('heading', 'Section heading'),
            new ArraySchema('items', 'Short imperative rules', new StringSchema('item', 'One rule')),
        ], ['heading', 'items']);

        $rules = new ObjectSchema('rules', 'Content for the project rules file', [
            new StringSchema('summary', 'One-paragraph summary of the project standards'),
            new ArraySchema('sections', 'Sections of rules', $section),
        ], ['summary', 'sections']);

        return new ObjectSchema('review', 'Review, phase outline and rules', [
            $assessment,
            new ArraySchema('phases', 'Three to six phases in the fixed order, outline only', $phase),
            $rules,
        ], ['assessment', 'phases', 'rules']);
    }

    public static function phaseSchema(): ObjectSchema
    {
        return new ObjectSchema('phase_body', 'The body of one phase', [
            new StringSchema('body', 'The complete prompt body for this phase, in Markdown'),
        ], ['body']);
    }
}
