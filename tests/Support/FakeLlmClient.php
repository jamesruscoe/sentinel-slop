<?php

namespace Tests\Support;

use App\Scanning\Contracts\LlmClient;
use App\Scanning\Synthesis\LlmResponse;

final class FakeLlmClient implements LlmClient
{
    /** @var list<array{stage: string, system: string, user: string, model: string}> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>|\Throwable  $response  the review reply (or an exception to throw on the review call)
     * @param  array<string, mixed>|\Throwable|null  $phaseResponse  a fixed phase reply, an exception for every phase call, or null for the default body
     */
    public function __construct(private readonly array|\Throwable $response, private readonly int $outputTokens = 900, private readonly array|\Throwable|null $phaseResponse = null) {}

    /**
     * The review reply: assessment, phase outline (with the finding ids each phase covers) and rules.
     *
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
            'phases' => array_map(fn (int $n) => ['phase' => $n, 'title' => "Phase {$n} title", 'goal' => "Phase {$n} is done.", 'addresses' => ["problem {$n}"], 'finding_ids' => $n === 1 ? ['F1'] : ($n === 2 ? ['F2'] : [])], [4, 3, 2, 1]),
            'rules' => [
                'summary' => 'Keep it tidy & typed.',
                'sections' => [
                    ['heading' => 'Errors', 'items' => ['Never swallow exceptions', 'Throw domain exceptions']],
                    ['heading' => 'Style', 'items' => ['Run Pint']],
                ],
            ],
        ];
    }

    public function review(string $systemPrompt, string $userPrompt, string $model): LlmResponse
    {
        $this->calls[] = ['stage' => 'review', 'system' => $systemPrompt, 'user' => $userPrompt, 'model' => $model];

        if ($this->response instanceof \Throwable) {
            throw $this->response;
        }

        return new LlmResponse($this->response, 'stop', (int) ceil(strlen($systemPrompt.$userPrompt) / 4), $this->outputTokens);
    }

    public function phase(string $systemPrompt, string $userPrompt, string $model): LlmResponse
    {
        $this->calls[] = ['stage' => 'phase', 'system' => $systemPrompt, 'user' => $userPrompt, 'model' => $model];

        if ($this->phaseResponse instanceof \Throwable) {
            throw $this->phaseResponse;
        }
        if ($this->phaseResponse !== null) {
            return new LlmResponse($this->phaseResponse, 'stop', (int) ceil(strlen($systemPrompt.$userPrompt) / 4), 300);
        }

        $number = preg_match('/## This phase: (\d+) of/', $userPrompt, $m) === 1 ? (int) $m[1] : 0;

        $body = "Run the full test suite first and confirm it is green before changing anything.\n\nDo phase {$number} things.\n\n- Fix a.php:3 as the finding describes, keeping the change scoped to that file.\n- Apply the same pattern wherever the aggregated findings show it recurs, one small commit at a time.\n\nRun the tests again at the end and stop if anything fails. Make no unrelated changes.";

        return new LlmResponse(['body' => $body], 'stop', (int) ceil(strlen($systemPrompt.$userPrompt) / 4), 300);
    }
}
