<?php

use App\Scanning\Exceptions\SynthesisException;
use App\Services\Llm\PrismLlmClient;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Tests\Support\FakeLlmClient;

test('the Prism client sends a structured request for the chosen model and returns the plan', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan()),
    ]);

    $plan = (new PrismLlmClient('anthropic', 1234, 30))->plan('SYSTEM PROMPT', 'USER PROMPT', 'claude-opus-5');

    expect($plan['phases'])->toHaveCount(5)->and($plan['rules']['summary'])->toBe('Keep it tidy & typed.');

    $fake->assertCallCount(1);
    $fake->assertRequest(function (array $requests) {
        expect($requests[0]->model())->toBe('claude-opus-5')
            ->and($requests[0]->prompt())->toBe('USER PROMPT')
            ->and($requests[0]->systemPrompts()[0]->content)->toBe('SYSTEM PROMPT')
            ->and($requests[0]->maxTokens())->toBe(1234);
    });
});

test('an empty structured response becomes a synthesis exception', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured([])]);

    expect(fn () => (new PrismLlmClient('anthropic'))->plan('s', 'u', 'claude-sonnet-5'))->toThrow(SynthesisException::class, 'no structured output');
});
