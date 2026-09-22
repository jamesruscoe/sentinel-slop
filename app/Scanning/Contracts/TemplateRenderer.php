<?php

declare(strict_types=1);

namespace App\Scanning\Contracts;

/**
 * Renders a named prompt template (resources/prompts/*.blade.php on the
 * Laravel side) with data. Kept behind an interface so the scanning core
 * has no dependency on Blade.
 */
interface TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string;
}
