<?php

namespace App\Jobs\Scan;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Exceptions\ScanException;
use App\Scanning\Fetch\ScanWorkspace;
use App\Scanning\Score\ScoreResult;
use App\Scanning\Synthesis\PromptSynthesiser;
use App\Scanning\Synthesis\RulesetLoader;
use App\Scanning\Synthesis\SynthesisRequest;
use App\Services\Scanning\ScanWorkspaceFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the redacted payload and asks the LLM for the phased plan. Failure
 * here is non-fatal: findings and score stay, and the reason is recorded in
 * scans.synthesis_error.
 */
class SynthesisePrompts extends ScanStageJob
{
    protected function status(): ?ScanStatus
    {
        return ScanStatus::Synthesising;
    }

    protected function process(Scan $scan, ScanWorkspace $workspace, ScanWorkspaceFactory $workspaces): void
    {
        $stack = Stack::fromArray($workspace->readArtifact('stack') ?? []);
        $findings = FindingCollection::fromArray(array_values((array) (($workspace->readArtifact('findings-normalised') ?? [])['findings'] ?? [])));
        $score = ScoreResult::fromArray($workspace->readArtifact('score') ?? []);
        $suppressions = $workspace->readArtifact('suppressions') ?? [];
        $editors = array_map(fn (string $e) => TargetEditor::from($e), (array) config('sentinel.synthesis.target_editors', ['claude_code', 'cursor']));

        $request = new SynthesisRequest(
            repositoryName: $scan->repository()->value('full_name') ?? 'repository',
            stack: $stack,
            score: $score,
            findings: $findings,
            rulesets: app(RulesetLoader::class)->load($stack),
            editors: $editors,
            model: $scan->llm_model ?? (string) config('sentinel.synthesis.model'),
            suppressions: [
                'count' => (int) ($suppressions['count'] ?? 0),
                'density' => (float) ($suppressions['density'] ?? 0),
                'by_kind' => array_map('intval', (array) ($suppressions['by_kind'] ?? [])),
            ],
        );

        try {
            $result = app(PromptSynthesiser::class)->synthesise($request);
        } catch (Throwable $e) {
            Log::warning('Prompt synthesis failed', ['scan' => $scan->uuid, 'exception' => get_class($e), 'message' => $e->getMessage()]);
            $scan->forceFill(['synthesis_error' => $e instanceof ScanException ? $e->userMessage() : 'Prompt generation failed unexpectedly.'])->save();

            return;
        }

        $scan->prompts()->delete();
        $scan->rulesFiles()->delete();

        foreach ($editors as $editor) {
            foreach ($result->promptsFor($editor) as $prompt) {
                $scan->prompts()->create(['target_editor' => $editor, 'phase' => $prompt['phase'], 'title' => $prompt['title'], 'body' => $prompt['body']]);
            }

            $rules = $result->rulesFileFor($editor);
            if ($rules !== null) {
                $scan->rulesFiles()->create(['target_editor' => $editor, 'filename' => $rules['filename'], 'body' => $rules['body']]);
            }
        }

        $scan->forceFill(['synthesis_error' => null])->save();
        $workspace->writeArtifact('synthesis', ['phases' => $result->phases, 'budget' => $result->budget]);
    }
}
