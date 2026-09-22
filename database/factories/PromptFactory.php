<?php

namespace Database\Factories;

use App\Models\Prompt;
use App\Models\Scan;
use App\Scanning\Enums\TargetEditor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prompt>
 */
class PromptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'target_editor' => TargetEditor::ClaudeCode,
            'phase' => fake()->numberBetween(1, 5),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
        ];
    }
}
