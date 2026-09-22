<?php

namespace Database\Factories;

use App\Models\Installation;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installation_id' => Installation::factory(),
            'github_repo_id' => fake()->unique()->numberBetween(1000, 99999999),
            'full_name' => fake()->userName().'/'.fake()->slug(2),
            'default_branch' => 'main',
            'is_private' => false,
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn () => ['removed_at' => now()]);
    }
}
