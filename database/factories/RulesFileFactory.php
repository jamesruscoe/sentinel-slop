<?php

namespace Database\Factories;

use App\Models\RulesFile;
use App\Models\Scan;
use App\Scanning\Enums\TargetEditor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RulesFile>
 */
class RulesFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'target_editor' => TargetEditor::ClaudeCode,
            'filename' => TargetEditor::ClaudeCode->rulesFilename(),
            'body' => fake()->paragraphs(3, true),
        ];
    }
}
