<?php

declare(strict_types=1);

namespace App\Scanning\Score;

final class ScoreResult
{
    /**
     * @param  array<string, int>  $bySeverity  severity => count
     */
    public function __construct(
        public readonly int $score,
        public readonly int $linesOfCode,
        public readonly int $penalty,
        public readonly float $density,
        public readonly int $suppressionPenalty,
        public readonly bool $criticalCapApplied,
        public readonly array $bySeverity,
        public readonly int $lowPenalty = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'lines_of_code' => $this->linesOfCode,
            'penalty' => $this->penalty,
            'density' => $this->density,
            'suppression_penalty' => $this->suppressionPenalty,
            'low_penalty' => $this->lowPenalty,
            'critical_cap_applied' => $this->criticalCapApplied,
            'by_severity' => $this->bySeverity,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['score'] ?? 0),
            (int) ($data['lines_of_code'] ?? 0),
            (int) ($data['penalty'] ?? 0),
            (float) ($data['density'] ?? 0),
            (int) ($data['suppression_penalty'] ?? 0),
            (bool) ($data['critical_cap_applied'] ?? false),
            array_map('intval', (array) ($data['by_severity'] ?? [])),
            (int) ($data['low_penalty'] ?? 0),
        );
    }
}
