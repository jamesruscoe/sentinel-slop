<?php

namespace App\Services\Llm;

use App\Scanning\Contracts\TemplateRenderer;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;

/**
 * Renders resources/prompts/<name>.blade.php. Template names are validated
 * so nothing outside the prompts directory can ever be rendered.
 */
final class BladeTemplateRenderer implements TemplateRenderer
{
    public function __construct(private readonly string $promptsPath) {}

    public function render(string $template, array $data): string
    {
        if (preg_match('~^[a-z0-9-]+(/[a-z0-9-]+)*$~', $template) !== 1) {
            throw new InvalidArgumentException("Invalid prompt template name: {$template}");
        }

        $file = rtrim($this->promptsPath, '/').'/'.$template.'.blade.php';

        if (! is_file($file)) {
            throw new InvalidArgumentException("Prompt template not found: {$template}");
        }

        return trim(View::file($file, $data)->render())."\n";
    }
}
