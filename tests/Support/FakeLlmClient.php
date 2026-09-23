<?php

namespace Tests\Support;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Synthesis\LlmResponse;

final class FakeLlmClient implements LlmClient
{
    /** @var list<array{system: string, user: string, model: string}> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>|\Throwable  $response
     */
    public function __construct(private readonly array|\Throwable $response, private readonly int $outputTokens = 900) {}

    /**
     * @return array<string, mixed>
     */
    public static function samplePlan(): array
    {
        return [
            'phases' => array_map(fn (int $n) => ['phase' => $n, 'title' => "Phase {$n} title", 'body' => "Do phase {$n} things.\n\n- Fix a.php:3"], [5, 4, 3, 2, 1]),
            'rules' => [
                'summary' => 'Keep it tidy & typed.',
                'sections' => [
                    ['heading' => 'Errors', 'items' => ['Never swallow exceptions', 'Throw domain exceptions']],
                    ['heading' => 'Style', 'items' => ['Run Pint']],
                ],
            ],
        ];
    }

    public function plan(string $systemPrompt, string $userPrompt, string $model): LlmResponse
    {
        $this->calls[] = ['system' => $systemPrompt, 'user' => $userPrompt, 'model' => $model];

        if ($this->response instanceof \Throwable) {
            throw $this->response;
        }

        return new LlmResponse($this->response, 'stop', (int) ceil(strlen($systemPrompt.$userPrompt) / 4), $this->outputTokens);
    }
}
