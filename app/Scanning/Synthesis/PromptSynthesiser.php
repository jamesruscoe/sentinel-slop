<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Contracts\TemplateRenderer;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Exceptions\SynthesisException;
use App\Scanning\Profile\ProfileFormatter;

/**
 * Synthesis in two kinds of call. The review call reads the profile and
 * the findings and returns the assessment, the outline of three to six
 * phases (title, goal, what each addresses, and which finding ids it acts
 * on) and the rules file. Then one call per phase writes that phase's body
 * from the outline plus only the findings assigned to it. A single call
 * producing everything reached 28k output tokens on a 21k-line repository;
 * split, no reply needs more than a few thousand, and a phase can be
 * regenerated on its own.
 *
 * Budgeting: the repository profile is sent first (capped at
 * profileTokenBudget), the findings payload takes what is left, and system
 * prompt + profile + findings + the reply allowance must fit in the model's
 * context window for every call or the request is refused up front.
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
        private readonly int $maxOutputTokens = 16000,
        private readonly int $contextWindow = 200000,
        private readonly int $profileTokenBudget = 6000,
        private readonly int $phaseMaxOutputTokens = 6000,
    ) {}

    public function synthesise(SynthesisRequest $request): SynthesisResult
    {
        $rulesetText = implode("\n\n", array_map(fn (string $name, string $md) => '## Sentinel Slop ruleset: '.ucfirst($name)." (not a file in the repository)\n\n{$md}", array_keys($request->rulesets), $request->rulesets));
        $system = $this->templates->render('system', ['rulesets' => $rulesetText, 'minPhases' => self::MIN_PHASES, 'maxPhases' => self::MAX_PHASES]);
        $phaseSystem = $this->templates->render('phase-system', ['rulesets' => $rulesetText]);

        $profileText = $this->profileText($request);
        $profileTokens = SynthesisPayloadBuilder::estimateTokens($profileText);

        $systemTokens = max(SynthesisPayloadBuilder::estimateTokens($system), SynthesisPayloadBuilder::estimateTokens($phaseSystem));
        $availableForFindings = $this->contextWindow - max($this->maxOutputTokens, $this->phaseMaxOutputTokens) - $systemTokens - $profileTokens - self::MARGIN_TOKENS;
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

        $this->assertFits($system, $user, $this->maxOutputTokens, 'review');

        $sent = ['system' => $system, 'user' => $user, 'model' => $request->model, 'calls' => [], 'phase_prompts' => []];

        try {
            $started = microtime(true);
            $review = $this->llm->review($system, $user, $request->model);
            $sent['calls'][] = ['stage' => 'review'] + ['seconds' => round(microtime(true) - $started, 1)] + $review->usage();
            $assessment = $this->validateAssessment($review->plan['assessment'] ?? null);
            $outline = $this->validateOutline($review->plan['phases'] ?? null);
            $rules = $this->validateRules($review->plan['rules'] ?? null);

            $phases = $this->writePhases($request, $outline, $assessment, $profileText, $payload['lines'], $phaseSystem, $sent);
        } catch (SynthesisException $e) {
            throw $e->withPayload($sent);
        }

        $sent['usage'] = $this->totalUsage($sent['calls']);

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
            'calls' => count($sent['calls']),
        ], $sent);
    }

    /**
     * One call per phase, in outline order. Each call sees the assessment
     * summary, the whole outline, the profile and only the finding lines the
     * review assigned to that phase; lines the review assigned to no phase
     * go to the last phase, labelled as such.
     *
     * @param  list<array{phase: int, title: string, goal: string, addresses: list<string>, finding_ids: list<string>}>  $outline
     * @param  Assessment  $assessment
     * @param  array<string, string>  $lines  finding id => rendered line
     * @param  array<string, mixed>  $sent
     * @return list<Phase>
     */
    private function writePhases(SynthesisRequest $request, array $outline, array $assessment, string $profileText, array $lines, string $phaseSystem, array &$sent): array
    {
        $assigned = [];
        foreach ($outline as $entry) {
            foreach ($entry['finding_ids'] as $id) {
                $assigned[$id] = true;
            }
        }
        $unassigned = array_diff_key($lines, $assigned);

        $phases = [];
        $last = count($outline) - 1;
        foreach ($outline as $index => $entry) {
            $own = [];
            foreach ($entry['finding_ids'] as $id) {
                if (isset($lines[$id])) {
                    $own[$id] = $lines[$id];
                }
            }
            $extra = $index === $last ? $unassigned : [];

            $phaseUser = $this->templates->render('phase-user', [
                'repository' => $request->repositoryName,
                'stack' => $request->stack,
                'score' => $request->score,
                'assessment' => $assessment,
                'outline' => $outline,
                'phase' => $entry,
                'profile' => $profileText,
                'findings' => implode("\n", [...$own, ...$extra]) ?: '(none assigned: work from the goal, the profile and the review)',
                'included' => count($own),
                'unassigned' => count($extra),
            ]);
            $this->assertFits($phaseSystem, $phaseUser, $this->phaseMaxOutputTokens, "phase {$entry['phase']}");

            $started = microtime(true);
            $response = $this->llm->phase($phaseSystem, $phaseUser, $request->model);
            $sent['calls'][] = ['stage' => 'phase '.$entry['phase']] + ['seconds' => round(microtime(true) - $started, 1)] + $response->usage();
            $sent['phase_prompts'][$entry['phase']] = $phaseUser;

            $body = trim((string) ($response->plan['body'] ?? ''));
            if (str_word_count($body) < 40) {
                throw new SynthesisException(sprintf('The model returned no usable body for phase %d ("%s").', $entry['phase'], $entry['title']));
            }

            $phases[] = ['phase' => $entry['phase'], 'title' => $entry['title'], 'goal' => $entry['goal'], 'body' => $body, 'addresses' => $entry['addresses']];
        }

        return $phases;
    }

    private function assertFits(string $system, string $user, int $outputAllowance, string $stage): void
    {
        $inputTokens = SynthesisPayloadBuilder::estimateTokens($system) + SynthesisPayloadBuilder::estimateTokens($user);
        if ($inputTokens + $outputAllowance > $this->contextWindow) {
            throw new SynthesisException(sprintf('The %s prompt (%d tokens) plus the reply allowance (%d tokens) exceed the %d-token context window.', $stage, $inputTokens, $outputAllowance, $this->contextWindow));
        }
    }

    /**
     * @param  list<array{stage: string, finish_reason: string, input_tokens: int, output_tokens: int}>  $calls
     * @return array{finish_reason: string, input_tokens: int, output_tokens: int}
     */
    private function totalUsage(array $calls): array
    {
        return [
            'finish_reason' => 'stop',
            'input_tokens' => array_sum(array_column($calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($calls, 'output_tokens')),
        ];
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
     * Three to six phases with distinct titles, renumbered in the order the
     * model gave them (the fixed order the system prompt requires).
     *
     * @return list<array{phase: int, title: string, goal: string, addresses: list<string>, finding_ids: list<string>}>
     */
    private function validateOutline(mixed $phases): array
    {
        if (! is_array($phases)) {
            throw new SynthesisException('The model returned no phases.');
        }

        $strings = fn (mixed $list): array => array_values(array_filter(array_map(fn ($i) => trim(is_scalar($i) ? (string) $i : ''), is_array($list) ? $list : []), fn (string $i) => $i !== ''));

        $usable = [];
        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }
            $title = trim((string) ($phase['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $usable[] = [
                'order' => (int) ($phase['phase'] ?? count($usable) + 1),
                'title' => $title,
                'goal' => trim((string) ($phase['goal'] ?? '')),
                'addresses' => $strings($phase['addresses'] ?? []),
                'finding_ids' => array_map(fn (string $id) => strtoupper(trim($id, "[] \t")), $strings($phase['finding_ids'] ?? [])),
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
            $numbered[] = ['phase' => $index + 1, 'title' => $phase['title'], 'goal' => $phase['goal'], 'addresses' => $phase['addresses'], 'finding_ids' => $phase['finding_ids']];
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
