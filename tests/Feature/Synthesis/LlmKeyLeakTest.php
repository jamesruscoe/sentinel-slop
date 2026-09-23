<?php

use App\Jobs\Scan\SynthesisePrompts;
use App\Models\Scan;
use App\Scanning\Exceptions\SynthesisException;
use App\Services\Llm\LlmSecretScrubber;
use App\Services\Llm\PrismLlmClient;
use Illuminate\Support\Facades\Http;

const LEAK_KEY = 'sk-ant-api03-LEAKTEST-0123456789abcdefghijklmnopqrstuvwxyz';

beforeEach(function () {
    config(['prism.providers.anthropic.api_key' => LEAK_KEY]);
});

test('a provider error never puts the API key into the exception message, chain or trace', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.LEAK_KEY]], 401)]);

    $thrown = null;
    try {
        (new PrismLlmClient('anthropic'))->plan('s', 'u', 'claude-sonnet-5');
    } catch (SynthesisException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()->and($thrown->getPrevious())->toBeNull();

    $seen = get_class($thrown).' '.$thrown->getMessage().' '.$thrown->getTraceAsString();
    expect($seen)->not->toContain(LEAK_KEY)->and($thrown->getMessage())->toContain('401');
});

test('the queued job carries only the scan model, never an LLM client or key', function () {
    $scan = Scan::factory()->create();

    expect(serialize(new SynthesisePrompts($scan)))->not->toContain(LEAK_KEY)->not->toContain('PrismLlmClient');
});

test('the scrubber removes every configured provider key and generic token shapes', function () {
    config(['prism.providers.openai.api_key' => 'sk-proj-OTHERKEY-abcdefghijklmnopqrstuvwxyz0123']);

    $scrubbed = LlmSecretScrubber::scrub('failed '.LEAK_KEY.' and sk-proj-OTHERKEY-abcdefghijklmnopqrstuvwxyz0123 and Authorization: Bearer zzz123');

    expect($scrubbed)->not->toContain('LEAKTEST')->not->toContain('OTHERKEY')->toContain('Bearer [REDACTED]')
        ->and(LlmSecretScrubber::scrub('plain message'))->toBe('plain message');
});
