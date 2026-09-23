<?php

declare(strict_types=1);

namespace App\Scanning\Score;

use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\Severity;

/**
 * 0-100, where 100 is clean. See CLAUDE.md for the curve.
 *
 *   penalty  = Σ weight[severity]
 *   density  = penalty / max(loc, 1) * 1000        weighted points per thousand lines
 *   base     = 100 · exp(-(density / scale)^exponent)
 *   supp     = min(suppression_cap, round(suppression_density · suppression_weight))
 *   score    = max(0, round(base) - supp), capped at critical_cap when any malware/secrets finding exists
 *
 * The stretched-exponential curve is flat near zero (a handful of findings
 * costs a few points), steepens through the middle and flattens again at
 * the bottom, so only genuinely dense problems go below 30.
 */
final class SlopScoreCalculator
{
    /**
     * @param  array<string, int|float>  $weights  severity value => points
     */
    public function __construct(
        private readonly array $weights,
        private readonly float $scale = 90.0,
        private readonly float $exponent = 2.1,
        private readonly int $criticalCap = 40,
        private readonly float $suppressionWeight = 2.0,
        private readonly int $suppressionCap = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `sentinel.score` config array.
     */
    public static function fromConfig(array $config): self
    {
        $curve = (array) ($config['curve'] ?? []);

        return new self(
            weights: array_map('floatval', (array) ($config['weights'] ?? [])),
            scale: max(1.0, (float) ($curve['scale'] ?? 90.0)),
            exponent: max(0.1, (float) ($curve['exponent'] ?? 2.1)),
            criticalCap: (int) ($config['critical_cap'] ?? 40),
            suppressionWeight: (float) ($config['suppression_weight'] ?? 2.0),
            suppressionCap: (int) ($config['suppression_cap'] ?? 20),
        );
    }

    public function calculate(FindingCollection $findings, int $linesOfCode, float $suppressionDensity = 0.0): ScoreResult
    {
        $penalty = 0.0;
        $bySeverity = array_fill_keys(array_map(fn (Severity $s) => $s->value, Severity::cases()), 0);
        $critical = false;

        foreach ($findings as $finding) {
            $penalty += (float) ($this->weights[$finding->severity->value] ?? 0);
            $bySeverity[$finding->severity->value]++;
            $critical = $critical || $finding->isSecurityCritical();
        }

        $density = round($penalty / max($linesOfCode, 1) * 1000, 2);
        $base = (int) round($this->curve($density));
        $suppressionPenalty = (int) min($this->suppressionCap, round($suppressionDensity * $this->suppressionWeight));
        $score = max(0, $base - $suppressionPenalty);

        if ($critical) {
            $score = min($score, $this->criticalCap);
        }

        return new ScoreResult($score, $linesOfCode, (int) round($penalty), $density, $suppressionPenalty, $critical && $score === $this->criticalCap, $bySeverity);
    }

    /**
     * The base score before suppression penalty and caps, 0-100.
     */
    public function curve(float $density): float
    {
        if ($density <= 0) {
            return 100.0;
        }

        return 100.0 * exp(-(($density / $this->scale) ** $this->exponent));
    }
}
