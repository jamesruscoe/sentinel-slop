<?php

declare(strict_types=1);

namespace App\Scanning\Score;

use App\Scanning\Data\Finding;
use App\Scanning\Data\FindingCollection;
use App\Scanning\Enums\Severity;

/**
 * 0-100, where 100 is clean. See CLAUDE.md for the reasoning.
 *
 *   penalty  = Σ weight[severity] over critical/high/medium findings only, excluding Structure findings
 *              and the size-scaling categories (duplication, complexity)
 *   density  = penalty / sqrt(max(KLOC, 1))        size gives some allowance, never a free pass
 *   base     = 100 · exp(-(density / scale)^exponent)
 *   size     = min(size_cap, base - base_including_size_scaling_findings)
 *              Duplicated blocks and oversized units accumulate with lines of code whatever the quality:
 *              laravel/framework had 293 duplication and 159 complexity mediums in 357k lines and scored 0.
 *              They are real work to do, so they cost points, but at most size_cap of them.
 *   struct   = min(structure_cap, base_with_size - base_including_structure_findings)
 *              Structure (absence) findings are the least verifiable we have: they move the score,
 *              never dominate it. Missing tests and no logging cost at most structure_cap points.
 *   low      = min(low_cap, low_count · low_points) capped: style noise cannot sink or mask a score
 *   supp     = min(suppression_cap, round(suppression_density · suppression_weight))
 *   score    = max(0, round(base) - size - struct - low - supp), capped at critical_cap when any malware/secrets finding exists
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
        private readonly int $structureCap = 15,
        private readonly string $structureTool = 'profile',
        private readonly int $sizeCap = 15,
        /** @var list<string> finding category values whose count grows with repository size */
        private readonly array $sizeCategories = ['duplication', 'complexity'],
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
            structureCap: (int) ($config['structure_cap'] ?? 15),
            structureTool: (string) ($config['structure_tool'] ?? 'profile'),
            sizeCap: (int) ($config['size_cap'] ?? 15),
            sizeCategories: array_values(array_map('strval', (array) ($config['size_categories'] ?? ['duplication', 'complexity']))),
        );
    }

    public function calculate(FindingCollection $findings, int $linesOfCode, float $suppressionDensity = 0.0): ScoreResult
    {
        $penalty = 0.0;
        $sizePenaltyPoints = 0.0;
        $sizeCount = 0;
        $structurePenaltyPoints = 0.0;
        $structureCount = 0;
        $bySeverity = array_fill_keys(array_map(fn (Severity $s) => $s->value, Severity::cases()), 0);
        $critical = false;

        foreach ($findings as $finding) {
            $bySeverity[$finding->severity->value]++;
            $critical = $critical || $finding->isSecurityCritical();
            if ($finding->severity->rank() < Severity::Medium->rank()) {
                continue;
            }
            $points = (float) ($this->weights[$finding->severity->value] ?? 0);
            if ($this->isStructure($finding)) {
                $structurePenaltyPoints += $points;
                $structureCount++;
            } elseif (in_array($finding->category->value, $this->sizeCategories, true)) {
                $sizePenaltyPoints += $points;
                $sizeCount++;
            } else {
                $penalty += $points;
            }
        }

        $divisor = sqrt(max($linesOfCode / 1000, 1.0));
        $density = round($penalty / $divisor, 2);
        $base = (int) round($this->curve($density));
        $baseWithSize = (int) round($this->curve(round(($penalty + $sizePenaltyPoints) / $divisor, 2)));
        $sizePenalty = (int) min($this->sizeCap, max(0, $base - $baseWithSize));
        $baseWithStructure = (int) round($this->curve(round(($penalty + $sizePenaltyPoints + $structurePenaltyPoints) / $divisor, 2)));
        $structurePenalty = (int) min($this->structureCap, max(0, $baseWithSize - $baseWithStructure));

        $lowPenalty = (int) min($this->lowCap, round($bySeverity[Severity::Low->value] * $this->lowPoints));
        $suppressionPenalty = (int) min($this->suppressionCap, round($suppressionDensity * $this->suppressionWeight));

        $scoreWithoutStructure = max(0, $base - $sizePenalty - $lowPenalty - $suppressionPenalty);
        $score = max(0, $base - $sizePenalty - $structurePenalty - $lowPenalty - $suppressionPenalty);
        if ($critical) {
            $score = min($score, $this->criticalCap);
            $scoreWithoutStructure = min($scoreWithoutStructure, $this->criticalCap);
        }

        return new ScoreResult($score, $linesOfCode, (int) round($penalty), $density, $suppressionPenalty, $critical && $score === $this->criticalCap, $bySeverity, $lowPenalty, $structurePenalty, $structureCount, $scoreWithoutStructure, $sizePenalty, $sizeCount);
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

    private function isStructure(Finding $finding): bool
    {
        return $finding->tool === $this->structureTool;
    }
}
