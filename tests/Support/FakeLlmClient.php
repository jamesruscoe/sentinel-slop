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
            'assessment' => [
                'summary' => 'The codebase is a small Laravel application whose controllers are thin and whose services carry the business logic, which is the right shape. The service layer has no logging and no tests link to it, so failures in the most important code leave no trace and regressions go unnoticed. The two changes that would most improve maintainability are adding a logging wrapper used by every service and writing unit tests for app/Services before touching anything else.',
                'strengths' => ['No secrets or malware-like patterns', 'Validation through Form Request classes'],
                'structural_problems' => [['title' => 'No logging in the service layer', 'evidence' => 'app/Services has 18 files and none logs', 'impact' => 'Handled failures are invisible in production']],
                'recommended_refactors' => [['title' => 'Introduce an audit logger', 'rationale' => 'Gives every service one way to record business events', 'scope' => 'app/Services', 'effort' => 'small']],
            ],
            'phases' => array_map(fn (int $n) => ['phase' => $n, 'title' => "Phase {$n} title", 'goal' => "Phase {$n} is done.", 'body' => "Do phase {$n} things.\n\n- Fix a.php:3", 'addresses' => ["problem {$n}"]], [4, 3, 2, 1]),
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
