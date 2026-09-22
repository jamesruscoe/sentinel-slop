<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Data\FindingCollection;
use App\Scanning\Data\Stack;
use App\Scanning\Enums\TargetEditor;
use App\Scanning\Score\ScoreResult;

final class SynthesisRequest
{
    /**
     * @param  array<string, string>  $rulesets  name => markdown
     * @param  list<TargetEditor>  $editors
     * @param  array{count: int, density: float, by_kind: array<string, int>}  $suppressions
     */
    public function __construct(
        public readonly string $repositoryName,
        public readonly Stack $stack,
        public readonly ScoreResult $score,
        public readonly FindingCollection $findings,
        public readonly array $rulesets,
        public readonly array $editors,
        public readonly string $model,
        public readonly array $suppressions = ['count' => 0, 'density' => 0.0, 'by_kind' => []],
    ) {}
}
