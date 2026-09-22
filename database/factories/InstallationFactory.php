<?php

namespace Database\Factories;

use App\Models\Installation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installation>
 */
class InstallationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'github_installation_id' => fake()->unique()->numberBetween(1000, 99999999),
            'account_id' => fake()->numberBetween(1000, 99999999),
            'account_login' => fake()->userName(),
            'account_type' => 'User',
            'suspended_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now()]);
    }

    public function organisation(): static
    {
        return $this->state(fn () => ['account_type' => 'Organization']);
    }
}
