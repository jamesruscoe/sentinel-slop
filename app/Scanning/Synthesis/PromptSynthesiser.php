<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Contracts\TemplateRenderer;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Exceptions\SynthesisException;
use App\Scanning\Profile\ProfileFormatter;

/**
 * One LLM call produces a review (assessment) plus an editor-neutral plan of
 * three to six phases and a rules file; templates then render the plan for
 * each target editor.
 *
 * Budgeting: the repository profile is sent first (capped at
 * profileTokenBudget), the findings payload takes what is left, and system
 * prompt + profile + findings + the reply (max_output_tokens) must fit in
 * the model's context window or the request is refused up front.
 *
 * @phpstan-import-type Phase from SynthesisResult
 * @phpstan-import-type Assessment from SynthesisResult
 */
final class PromptSynthesiser
{
    public const MIN_PHASES = 3;

    public const MAX_PHASES = 6;

    /** Headroom for template text, tokeniser variance and the schema. */
    private const MARGIN_TOKENS = 2000;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly TemplateRenderer $templates,
        private readonly SynthesisPayloadBuilder $payloads,
        private readonly int $maxOutputTokens = 32000,
        private readonly int $contextWindow = 200000,
        private readonly int $profileTokenBudget = 6000,
    ) {}

    public function synthesise(SynthesisRequest $request): SynthesisResult
    {
        $rulesetText = implode("\n\n", array_map(fn (string $name, string $md) => '## Sentinel Slop ruleset: '.ucfirst($name)." (not a file in the repository)\n\n{$md}", array_keys($request->rulesets), $request->rulesets));
        $system = $this->templates->render('system', ['rulesets' => $rulesetText, 'minPhases' => self::MIN_PHASES, 'maxPhases' => self::MAX_PHASES]);

        $profileText = $this->profileText($request);
        $profileTokens = SynthesisPayloadBuilder::estimateTokens($profileText);

        $systemTokens = SynthesisPayloadBuilder::estimateTokens($system);
        $availableForFindings = $this->contextWindow - $this->maxOutputTokens - $systemTokens - $profileTokens - self::MARGIN_TOKENS;
        if ($availableForFindings < 500) {
            throw new SynthesisException(sprintf('The system prompt (%d tokens), the profile (%d tokens) and the reply allowance (%d tokens) do not fit in the %d-token context window.', $systemTokens, $profileTokens, $this->maxOutputTokens, $this->contextWindow));
        }

        $payload = $this->payloads->build($request->findings, maxTokens: $availableForFindings);

        $user = $this->templates->render('user', [
            'repository' => $request->repositoryName,
            'stack' => $request->stack,
            'score' => $request->score,
            'profile' => $profileText,
            'findings' => $payload['text'],
            'included' => $payload['included'],
            'omitted' => $payload['omitted'],
            'aggregated' => $payload['aggregated'],
            'total' => $payload['total'],
            'byCategory' => $payload['by_category'],
            'suppressions' => $request->suppressions,
        ]);

        $inputTokens = $systemTokens + SynthesisPayloadBuilder::estimateTokens($user);
        if ($inputTokens + $this->maxOutputTokens > $this->contextWindow) {
            throw new SynthesisException(sprintf('The prompt (%d tokens) plus the reply allowance (%d tokens) exceed the %d-token context window.', $inputTokens, $this->maxOutputTokens, $this->contextWindow));
        }

        $sent = ['system' => $system, 'user' => $user, 'model' => $request->model];

        try {
            $response = $this->llm->plan($system, $user, $request->model);
            $assessment = $this->validateAssessment($response->plan['assessment'] ?? null);
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

        return new SynthesisResult($assessment, $phases, $prompts, $rulesFiles, [
            'included' => $payload['included'],
            'omitted' => $payload['omitted'],
            'aggregated' => $payload['aggregated'],
            'estimated_tokens' => $payload['estimated_tokens'],
            'profile_tokens' => $profileTokens,
        ], $sent);
    }

    /**
     * The profile as the reviewer sees it: the same text a person reads on
     * the results page, cut at the token budget if a repository is enormous.
     */
    private function profileText(SynthesisRequest $request): string
    {
        if ($request->profile === null) {
            return '';
        }

        $text = ProfileFormatter::render($request->profile);
        $limit = $this->profileTokenBudget * 3;
        if (strlen($text) > $limit) {
            $text = substr($text, 0, $limit)."\n(profile truncated to fit the budget)";
        }

        return $text;
    }

    /**
     * @return Assessment
     */
    private function validateAssessment(mixed $assessment): array
    {
        if (! is_array($assessment)) {
            throw new SynthesisException('The model returned no assessment.');
        }

        $summary = trim((string) ($assessment['summary'] ?? ''));
        if (str_word_count($summary) < 40) {
            throw new SynthesisException('The model returned an assessment summary of under 40 words.');
        }

        $strings = fn (mixed $list): array => array_values(array_filter(array_map(fn ($i) => trim(is_scalar($i) ? (string) $i : ''), is_array($list) ? $list : []), fn (string $i) => $i !== ''));
        $records = function (mixed $list, array $keys): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $record = [];
                foreach ($keys as $key) {
                    $record[$key] = trim((string) ($item[$key] ?? ''));
                }
                if ($record['title'] !== '') {
                    $out[] = $record;
                }
            }

            return $out;
        };

        return [
            'summary' => $summary,
            'strengths' => $strings($assessment['strengths'] ?? []),
            'structural_problems' => $records($assessment['structural_problems'] ?? [], ['title', 'evidence', 'impact']),
            'recommended_refactors' => $records($assessment['recommended_refactors'] ?? [], ['title', 'rationale', 'scope', 'effort']),
        ];
    }

    /**
     * Three to six phases with distinct titles and non-empty bodies, renumbered
     * in the order the model gave them (its impact order).
     *
     * @return list<Phase>
     */
    private function validatePhases(mixed $phases): array
    {
        if (! is_array($phases)) {
            throw new SynthesisException('The model returned no phases.');
        }

        $usable = [];
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $body = trim((string) ($phase['body'] ?? ''));
            $title = trim((string) ($phase['title'] ?? ''));
            if ($body === '' || $title === '') {
                continue;
            }
            $usable[] = [
                'order' => (int) ($phase['phase'] ?? count($usable) + 1),
                'title' => $title,
                'goal' => trim((string) ($phase['goal'] ?? '')),
                'body' => $body,
                'addresses' => array_values(array_filter(array_map(fn ($a) => trim(is_scalar($a) ? (string) $a : ''), is_array($phase['addresses'] ?? null) ? $phase['addresses'] : []), fn (string $a) => $a !== '')),
            ];
        }

        if (count($usable) < self::MIN_PHASES || count($usable) > self::MAX_PHASES) {
            throw new SynthesisException(sprintf('The model returned %d usable phases; between %d and %d are required.', count($usable), self::MIN_PHASES, self::MAX_PHASES));
        }

        $titles = array_map(fn (array $p) => strtolower($p['title']), $usable);
        if (count(array_unique($titles)) !== count($titles)) {
            throw new SynthesisException('The model returned phases with duplicate titles.');
        }

        usort($usable, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $numbered = [];
        foreach ($usable as $index => $phase) {
            $numbered[] = ['phase' => $index + 1, 'title' => $phase['title'], 'goal' => $phase['goal'], 'body' => $phase['body'], 'addresses' => $phase['addresses']];
        }

        return $numbered;
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
