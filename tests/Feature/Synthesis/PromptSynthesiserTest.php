<?php

use App\Scanning\Contracts\TemplateRenderer;
use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Exceptions\SynthesisException;
use App\Scanning\Score\ScoreResult;
use App\Scanning\Synthesis\PromptSynthesiser;
use App\Scanning\Synthesis\RulesetLoader;
use App\Scanning\Synthesis\SynthesisPayloadBuilder;
use App\Scanning\Synthesis\SynthesisRequest;
use Tests\Support\FakeLlmClient;

function synthesisRequest(): SynthesisRequest
{
    $stack = new Stack(['PHP' => 900, 'JavaScript' => 100], ['laravel'], ['phpstan', 'pint'], ['composer.json'], ['composer'], [], ['laravel' => '^12.0', 'php' => '^8.3']);

    return new SynthesisRequest(
        repositoryName: 'acme/app',
        stack: $stack,
        score: new ScoreResult(61, 4200, 80, 19.05, 4, false, ['critical' => 0, 'high' => 2, 'medium' => 5, 'low' => 10, 'info' => 0]),
        findings: new FindingCollection([
            Finding::fromArray(['tool' => 'gitleaks', 'rule_id' => 'generic-api-key', 'category' => 'secrets', 'severity' => 'critical', 'file_path' => 'config/x.php', 'line' => 9, 'message' => 'Possible secret', 'snippet' => null]),
            Finding::fromArray(['tool' => 'phpstan', 'rule_id' => 'return.type', 'category' => 'type_safety', 'severity' => 'medium', 'file_path' => 'app/A.php', 'line' => 3, 'symbol' => 'App\A::total()', 'message' => 'bad return', 'snippet' => 'return "0";']),
        ]),
        rulesets: (new RulesetLoader(resource_path('rulesets')))->load($stack),
        editors: [TargetEditor::ClaudeCode, TargetEditor::Cursor],
        model: 'claude-sonnet-5',
        suppressions: ['count' => 3, 'density' => 0.71, 'by_kind' => ['phpstan' => 3]],
    );
}

test('the synthesiser sends stack, score, rulesets and redacted findings and renders per-editor output', function () {
    $llm = new FakeLlmClient(FakeLlmClient::samplePlan());
    $synthesiser = new PromptSynthesiser($llm, app(TemplateRenderer::class), new SynthesisPayloadBuilder);

    $result = $synthesiser->synthesise(synthesisRequest());

    expect($llm->calls)->toHaveCount(1)
        ->and($llm->calls[0]['model'])->toBe('claude-sonnet-5')
        ->and($llm->calls[0]['system'])->toContain('## Ruleset: laravel', 'strict_types', '1. Ensure tests exist and pass')
        ->and($llm->calls[0]['system'])->toContain('Never infer, guess or invent a class, method, function or variable name')
        ->and($llm->calls[0]['user'])->toContain('acme/app', 'Slop score: 61/100', 'PHP (90%)', 'laravel 12', 'Runtimes declared: PHP 8.3', 'config/x.php:9', 'app/A.php:3 in App\A::total()', 'return "0";', 'Inline suppression comments: 3');

    $claude = $result->promptsFor(TargetEditor::ClaudeCode);
    $cursor = $result->promptsFor(TargetEditor::Cursor);

    expect(array_column($claude, 'phase'))->toBe([1, 2, 3, 4, 5])
        ->and($claude[0]['title'])->toBe('Phase 1 title')
        ->and($claude[0]['body'])->toContain('phase 1 of 5', 'Paste this into Claude Code', 'Do phase 1 things.', 'Run the full test suite')
        ->and($claude[0]['body'])->toContain('before moving to phase 2')
        ->and($claude[4]['body'])->toContain('This is the final phase.')->not->toContain('phase 6')
        ->and($cursor[4]['body'])->toContain('Paste this into Cursor', 'Do phase 5 things.')
        ->and($result->rulesFileFor(TargetEditor::ClaudeCode))->toMatchArray(['filename' => 'CLAUDE.md'])
        ->and($result->rulesFileFor(TargetEditor::ClaudeCode)['body'])->toContain('# acme/app conventions', 'Keep it tidy & typed.', '## Errors', '- Never swallow exceptions', 'Stack: laravel 12 on PHP 8.3. Rulesets applied: php, laravel')
        ->and($result->payload['usage'])->toMatchArray(['finish_reason' => 'stop', 'output_tokens' => 900])
        ->and($result->rulesFileFor(TargetEditor::Cursor)['filename'])->toBe('.cursor/rules/sentinel-slop.mdc')
        ->and($result->rulesFileFor(TargetEditor::Cursor)['body'])->toStartWith("---\ndescription: acme/app conventions")
        ->and($result->rulesFileFor(TargetEditor::Cursor)['body'])->toContain('alwaysApply: true')
        ->and($result->budget['included'])->toBe(2);
});

test('an incomplete plan is rejected rather than stored', function () {
    $plan = FakeLlmClient::samplePlan();
    unset($plan['phases'][2]);

    $synthesiser = new PromptSynthesiser(new FakeLlmClient($plan), app(TemplateRenderer::class), new SynthesisPayloadBuilder);
    expect(fn () => $synthesiser->synthesise(synthesisRequest()))->toThrow(SynthesisException::class, '4 usable phases');

    $plan = FakeLlmClient::samplePlan();
    $plan['rules'] = ['summary' => 'x', 'sections' => []];
    $synthesiser = new PromptSynthesiser(new FakeLlmClient($plan), app(TemplateRenderer::class), new SynthesisPayloadBuilder);
    expect(fn () => $synthesiser->synthesise(synthesisRequest()))->toThrow(SynthesisException::class, 'no rules sections');
});

test('the findings payload is capped so the reply always fits the context window, and impossible budgets are refused up front', function () {
    $llm = new FakeLlmClient(FakeLlmClient::samplePlan());
    $findings = new FindingCollection(array_map(fn (int $i) => Finding::fromArray(['tool' => 'x', 'rule_id' => "r{$i}", 'category' => 'style', 'severity' => 'low', 'file_path' => "f{$i}.php", 'line' => $i, 'message' => str_repeat('word ', 60)]), range(1, 200)));
    $request = new SynthesisRequest('acme/app', new Stack(['PHP' => 1]), synthesisRequest()->score, $findings, [], [TargetEditor::ClaudeCode], 'm');

    // 200 findings of ~100 tokens each need ~20k tokens; a 30k window minus 16k reply minus the system prompt leaves room for only a fraction.
    $result = (new PromptSynthesiser($llm, app(TemplateRenderer::class), new SynthesisPayloadBuilder(tokenBudget: 100000), maxOutputTokens: 16000, contextWindow: 30000))->synthesise($request);
    $inputTokens = SynthesisPayloadBuilder::estimateTokens($llm->calls[0]['system'].$llm->calls[0]['user']);

    expect($result->budget['included'])->toBeLessThan(200)->toBeGreaterThan(10)
        ->and($inputTokens + 16000)->toBeLessThanOrEqual(30000);

    $tight = new PromptSynthesiser(new FakeLlmClient(FakeLlmClient::samplePlan()), app(TemplateRenderer::class), new SynthesisPayloadBuilder, maxOutputTokens: 16000, contextWindow: 17000);
    expect(fn () => $tight->synthesise($request))->toThrow(SynthesisException::class, 'do not fit in the 17000-token context window');
});

test('template names cannot escape the prompts directory', function () {
    expect(fn () => app(TemplateRenderer::class)->render('../../config/app', []))->toThrow(InvalidArgumentException::class);
});
