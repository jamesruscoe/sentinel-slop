<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\Repository;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'user_id' => null,
            'status' => ScanStatus::Queued,
            'commit_sha' => null,
            'slop_score' => null,
            'detected_stack' => null,
            'skipped_files' => null,
            'lines_of_code' => null,
            'error_message' => null,
        ];
    }

    public function complete(?int $score = null): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Complete,
            'commit_sha' => fake()->sha1(),
            'slop_score' => $score ?? fake()->numberBetween(0, 100),
            'detected_stack' => ['languages' => ['PHP' => 100], 'frameworks' => ['laravel']],
            'skipped_files' => ['total' => 0, 'counts' => [], 'entries' => [], 'truncated' => false],
            'lines_of_code' => fake()->numberBetween(100, 20000),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $message = 'Something went wrong.'): static
    {
        return $this->state(fn () => [
            'status' => ScanStatus::Failed,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
