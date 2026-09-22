<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

/**
 * Extracts dependency names from pyproject.toml with plain text matching.
 * Deliberately not a full TOML parser: the file is only ever read as data
 * and we only need package names.
 */
final class PyprojectParser
{
    public const FRAMEWORKS = [
        'django' => 'django',
        'flask' => 'flask',
        'fastapi' => 'fastapi',
        'starlette' => 'starlette',
        'sanic' => 'sanic',
        'tornado' => 'tornado',
        'pyramid' => 'pyramid',
    ];

    public const TOOLING = [
        'ruff' => 'ruff',
        'mypy' => 'mypy',
        'black' => 'black',
        'flake8' => 'flake8',
        'pylint' => 'pylint',
        'pytest' => 'pytest',
        'isort' => 'isort',
        'pyright' => 'pyright',
    ];

    public static function parse(string $toml): ManifestData
    {
        $dependencies = [];

        // PEP 621: dependencies = ["django>=4.2", "requests"] and optional-dependency groups.
        if (preg_match_all('/^\s*(?:dependencies|[\w-]+)\s*=\s*\[(.*?)\]/ms', $toml, $matches)) {
            foreach ($matches[1] as $list) {
                preg_match_all('/["\']([A-Za-z0-9_.-]+)/', $list, $names);
                array_push($dependencies, ...$names[1]);
            }
        }

        // Poetry tables: [tool.poetry.dependencies], [tool.poetry.group.dev.dependencies].
        if (preg_match_all('/^\[tool\.poetry\.(?:group\.[\w-]+\.)?(?:dev-)?dependencies\]\s*$(.*?)(?=^\[|\z)/ms', $toml, $tables)) {
            foreach ($tables[1] as $table) {
                preg_match_all('/^\s*([A-Za-z0-9_.-]+)\s*=/m', $table, $names);
                array_push($dependencies, ...$names[1]);
            }
        }

        $dependencies = array_values(array_unique(array_filter(
            array_map('strtolower', $dependencies),
            fn (string $name) => $name !== 'python',
        )));

        $manifest = ManifestData::fromDependencies('pip', $dependencies, self::FRAMEWORKS, self::TOOLING);

        $tooling = $manifest->tooling;
        foreach (array_keys(self::TOOLING) as $tool) {
            if (preg_match('/^\[tool\.'.preg_quote($tool, '/').'(\.|\])/m', $toml) === 1) {
                $tooling[] = $tool;
            }
        }

        return new ManifestData('pip', $manifest->dependencies, $manifest->frameworks, array_values(array_unique($tooling)));
    }
}
