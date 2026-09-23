<?php

declare(strict_types=1);

namespace App\Scanning\Score;

use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\Severity;

/**
 * 0-100, where 100 is clean. See CLAUDE.md for the reasoning.
 *
 *   penalty  = Σ weight[severity] over critical/high/medium findings only
 *   density  = penalty / sqrt(max(KLOC, 1))        size gives some allowance, never a free pass
 *   base     = 100 · exp(-(density / scale)^exponent)
 *   low      = min(low_cap, low_count · low_points) capped: style noise cannot sink or mask a score
 *   supp     = min(suppression_cap, round(suppression_density · suppression_weight))
 *   score    = max(0, round(base) - low - supp), capped at critical_cap when any malware/secrets finding exists
 */
final class SlopScoreCalculator
{
    /**
     * @param  array<string, int|float>  $weights  severity value => points (low/info ignored here)
     */
    public function __construct(
        private readonly array $weights,
        private readonly float $scale = 65.0,
        private readonly float $exponent = 1.4,
        private readonly int $criticalCap = 40,
        private readonly float $lowPoints = 0.1,
        private readonly int $lowCap = 5,
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
            scale: max(1.0, (float) ($curve['scale'] ?? 65.0)),
            exponent: max(0.1, (float) ($curve['exponent'] ?? 1.4)),
            criticalCap: (int) ($config['critical_cap'] ?? 40),
            lowPoints: (float) ($config['low_points'] ?? 0.1),
            lowCap: (int) ($config['low_cap'] ?? 5),
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
            $bySeverity[$finding->severity->value]++;
            $critical = $critical || $finding->isSecurityCritical();

            if ($finding->severity->rank() >= Severity::Medium->rank()) {
                $penalty += (float) ($this->weights[$finding->severity->value] ?? 0);
            }
        }

        $density = round($penalty / sqrt(max($linesOfCode / 1000, 1.0)), 2);
        $base = (int) round($this->curve($density));
        $lowPenalty = (int) min($this->lowCap, round($bySeverity[Severity::Low->value] * $this->lowPoints));
        $suppressionPenalty = (int) min($this->suppressionCap, round($suppressionDensity * $this->suppressionWeight));
        $score = max(0, $base - $lowPenalty - $suppressionPenalty);

        if ($critical) {
            $score = min($score, $this->criticalCap);
        }

        return new ScoreResult($score, $linesOfCode, (int) round($penalty), $density, $suppressionPenalty, $critical && $score === $this->criticalCap, $bySeverity, $lowPenalty);
    }

    /**
     * The base score before the low, suppression and critical adjustments, 0-100.
     */
    public function curve(float $density): float
    {
        if ($density <= 0) {
            return 100.0;
        }

        return 100.0 * exp(-(($density / $this->scale) ** $this->exponent));
    }
}
