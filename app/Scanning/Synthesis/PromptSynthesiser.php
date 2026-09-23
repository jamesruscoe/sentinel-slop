<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Contracts\TemplateRenderer;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Exceptions\SynthesisException;

/**
 * One LLM call produces an editor-neutral plan (five phases plus rules);
 * templates then render it for each target editor.
 *
 * Budgeting: the findings payload is trimmed so that system prompt +
 * findings + the reply (max_output_tokens) always fit in the model's
 * context window, and the request is refused up front if they cannot.
 */
final class PromptSynthesiser
{
    public const PHASES = [
        1 => 'Ensure tests exist and pass',
        2 => 'Security issues and secrets',
        3 => 'Dead code, duplicates and hallucinated dependencies',
        4 => 'Error handling',
        5 => 'Style and consistency',
    ];

    /** Headroom for template text, tokeniser variance and the schema. */
    private const MARGIN_TOKENS = 2000;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly TemplateRenderer $templates,
        private readonly SynthesisPayloadBuilder $payloads,
        private readonly int $maxOutputTokens = 32000,
        private readonly int $contextWindow = 200000,
    ) {}

    public function synthesise(SynthesisRequest $request): SynthesisResult
    {
        $rulesetText = implode("\n\n", array_map(fn (string $name, string $md) => "## Ruleset: {$name}\n\n{$md}", array_keys($request->rulesets), $request->rulesets));
        $system = $this->templates->render('system', ['rulesets' => $rulesetText, 'phases' => self::PHASES]);

        $systemTokens = SynthesisPayloadBuilder::estimateTokens($system);
        $availableForFindings = $this->contextWindow - $this->maxOutputTokens - $systemTokens - self::MARGIN_TOKENS;
        if ($availableForFindings < 500) {
            throw new SynthesisException(sprintf('The system prompt (%d tokens) plus the reply allowance (%d tokens) do not fit in the %d-token context window.', $systemTokens, $this->maxOutputTokens, $this->contextWindow));
        }

        $payload = $this->payloads->build($request->findings, maxTokens: $availableForFindings);

        $user = $this->templates->render('user', [
            'repository' => $request->repositoryName,
            'stack' => $request->stack,
            'score' => $request->score,
            'findings' => $payload['text'],
            'included' => $payload['included'],
            'omitted' => $payload['omitted'],
            'aggregated' => $payload['aggregated'],
            'total' => $payload['total'],
            'byCategory' => $payload['by_category'],
            'suppressions' => $request->suppressions,
            'phases' => self::PHASES,
        ]);

        $inputTokens = $systemTokens + SynthesisPayloadBuilder::estimateTokens($user);
        if ($inputTokens + $this->maxOutputTokens > $this->contextWindow) {
            throw new SynthesisException(sprintf('The prompt (%d tokens) plus the reply allowance (%d tokens) exceed the %d-token context window.', $inputTokens, $this->maxOutputTokens, $this->contextWindow));
        }

        $sent = ['system' => $system, 'user' => $user, 'model' => $request->model];

        try {
            $response = $this->llm->plan($system, $user, $request->model);
            $phases = $this->validatePhases($response->plan['phases'] ?? null);
            $rules = $this->validateRules($response->plan['rules'] ?? null);
        } catch (SynthesisException $e) {
            throw $e->withPayload($sent);
        }

        $sent['usage'] = $response->usage();

        $prompts = [];
        $rulesFiles = [];

        foreach ($request->editors as $editor) {
            $prompts[$editor->value] = array_map(fn (array $phase) => [
                'phase' => $phase['phase'],
                'title' => $phase['title'],
                'body' => $this->templates->render("editors/{$this->slug($editor)}/phase", ['editor' => $editor, 'phase' => $phase, 'phases' => $phases, 'repository' => $request->repositoryName]),
            ], $phases);

            $rulesFiles[$editor->value] = [
                'filename' => $editor->rulesFilename(),
                'body' => $this->templates->render("editors/{$this->slug($editor)}/rules", ['editor' => $editor, 'rules' => $rules, 'stack' => $request->stack, 'rulesets' => array_keys($request->rulesets), 'repository' => $request->repositoryName]),
            ];
        }

        return new SynthesisResult($phases, $prompts, $rulesFiles, [
            'included' => $payload['included'],
            'omitted' => $payload['omitted'],
            'aggregated' => $payload['aggregated'],
            'estimated_tokens' => $payload['estimated_tokens'],
        ], $sent);
    }

    /**
     * @return list<array{phase: int, title: string, body: string}>
     */
    private function validatePhases(mixed $phases): array
    {
        if (! is_array($phases)) {
            throw new SynthesisException('The model returned no phases.');
        }

        $byNumber = [];
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $number = (int) ($phase['phase'] ?? 0);
            $body = trim((string) ($phase['body'] ?? ''));
            if ($number >= 1 && $number <= 5 && $body !== '') {
                $byNumber[$number] = ['phase' => $number, 'title' => trim((string) ($phase['title'] ?? '')) ?: self::PHASES[$number], 'body' => $body];
            }
        }

        if (count($byNumber) !== 5) {
            throw new SynthesisException('The model returned '.count($byNumber).' usable phases instead of 5.');
        }

        ksort($byNumber);

        return array_values($byNumber);
    }

    /**
     * @return array{summary: string, sections: list<array{heading: string, items: list<string>}>}
     */
    private function validateRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            throw new SynthesisException('The model returned no rules file content.');
        }

        $sections = [];
        foreach ((array) ($rules['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $items = array_values(array_filter(array_map(fn ($i) => trim((string) $i), (array) ($section['items'] ?? [])), fn (string $i) => $i !== ''));
            $heading = trim((string) ($section['heading'] ?? ''));
            if ($heading !== '' && $items !== []) {
                $sections[] = ['heading' => $heading, 'items' => $items];
            }
        }

        if ($sections === []) {
            throw new SynthesisException('The model returned no rules sections.');
        }

        return ['summary' => trim((string) ($rules['summary'] ?? '')), 'sections' => $sections];
    }

    private function slug(TargetEditor $editor): string
    {
        return str_replace('_', '-', $editor->value);
    }
}
