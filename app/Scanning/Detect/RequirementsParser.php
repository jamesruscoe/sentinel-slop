<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

final class RequirementsParser
{
    public static function parse(string $text): ManifestData
    {
        $dependencies = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '-')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_.-]+)/', $line, $match) === 1) {
                $dependencies[] = strtolower($match[1]);
            }
        }

        return ManifestData::fromDependencies('pip', array_values(array_unique($dependencies)), PyprojectParser::FRAMEWORKS, PyprojectParser::TOOLING);
    }
}
