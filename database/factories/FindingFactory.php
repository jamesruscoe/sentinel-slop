<?php

namespace Database\Factories;

use App\Models\Finding;
use App\Models\Scan;
use App\Scanning\Enums\FindingCategory;
use App\Scanning\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'tool' => fake()->randomElement(['phpstan', 'eslint', 'semgrep', 'gitleaks', 'jscpd', 'heuristic']),
            'rule_id' => fake()->slug(2),
            'category' => fake()->randomElement(FindingCategory::cases()),
            'severity' => fake()->randomElement(Severity::cases()),
            'file_path' => 'src/'.fake()->slug(1).'.php',
            'line' => fake()->numberBetween(1, 400),
            'message' => fake()->sentence(),
            'snippet' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => Severity::Critical, 'category' => FindingCategory::Secrets]);
    }
}
