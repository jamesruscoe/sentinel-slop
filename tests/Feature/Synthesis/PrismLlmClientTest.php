<?php

use App\Scanning\Exceptions\SynthesisException;
use App\Services\Llm\PrismLlmClient;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\Support\FakeLlmClient;

test('the Prism client sends a structured request for the chosen model and returns the plan', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan()),
    ]);

    $response = (new PrismLlmClient('anthropic', 1234, 30))->plan('SYSTEM PROMPT', 'USER PROMPT', 'claude-opus-5');

    expect($response->plan['phases'])->toHaveCount(5)->and($response->plan['rules']['summary'])->toBe('Keep it tidy & typed.')->and($response->finishReason)->toBe('stop');

    $fake->assertCallCount(1);
    $fake->assertRequest(function (array $requests) {
        expect($requests[0]->model())->toBe('claude-opus-5')
            ->and($requests[0]->prompt())->toBe('USER PROMPT')
            ->and($requests[0]->systemPrompts()[0]->content)->toBe('SYSTEM PROMPT')
            ->and($requests[0]->maxTokens())->toBe(1234);
    });
});

test('an empty structured response becomes a synthesis exception', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured([])->withFinishReason(FinishReason::Stop)]);

    expect(fn () => (new PrismLlmClient('anthropic'))->plan('s', 'u', 'claude-sonnet-5'))->toThrow(SynthesisException::class, 'no parseable structured output');
});

test('a reply cut off by the output limit fails loudly even if it decoded to something', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan())->withFinishReason(FinishReason::Length)->withUsage(new Usage(500, 16000))]);

    expect(fn () => (new PrismLlmClient('anthropic', 16000))->plan('s', 'u', 'claude-sonnet-5'))
        ->toThrow(SynthesisException::class, 'cut off by the output limit (16000 output tokens of 16000 allowed');
});

test('any other abnormal finish reason fails loudly', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured(FakeLlmClient::samplePlan())->withFinishReason(FinishReason::ContentFilter)]);

    expect(fn () => (new PrismLlmClient('anthropic'))->plan('s', 'u', 'claude-sonnet-5'))->toThrow(SynthesisException::class, 'unexpected reason: ContentFilter');
});
