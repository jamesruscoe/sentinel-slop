<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

use App\Scanning\Support\PathMatcher;

/**
 * Records which tools a project claims to use from the presence of their
 * config files. Presence only: this class never opens the files.
 */
final class ToolingDetector
{
    /** @var array<string, list<string>>  tool => basename globs at repo root */
    private const CONFIG_FILES = [
        'phpstan' => ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'],
        'psalm' => ['psalm.xml', 'psalm.xml.dist'],
        'pint' => ['pint.json'],
        'php-cs-fixer' => ['.php-cs-fixer.php', '.php-cs-fixer.dist.php', '.php_cs', '.php_cs.dist'],
        'phpcs' => ['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml'],
        'phpunit' => ['phpunit.xml', 'phpunit.xml.dist'],
        'rector' => ['rector.php'],
        'eslint' => ['eslint.config.*', '.eslintrc', '.eslintrc.*'],
        'prettier' => ['.prettierrc', '.prettierrc.*', 'prettier.config.*'],
        'biome' => ['biome.json', 'biome.jsonc'],
        'jest' => ['jest.config.*'],
        'vitest' => ['vitest.config.*'],
        'playwright' => ['playwright.config.*'],
        'cypress' => ['cypress.config.*'],
        'typescript' => ['tsconfig.json'],
        'tailwind' => ['tailwind.config.*'],
        'vite' => ['vite.config.*'],
        'webpack' => ['webpack.config.*'],
        'editorconfig' => ['.editorconfig'],
        'docker' => ['Dockerfile'],
        'docker-compose' => ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'],
        'ruff' => ['ruff.toml', '.ruff.toml'],
        'mypy' => ['mypy.ini', '.mypy.ini'],
        'tox' => ['tox.ini'],
        'pre-commit' => ['.pre-commit-config.yaml'],
        'make' => ['Makefile'],
    ];

    /** @var array<string, string>  lockfile basename => package manager */
    private const LOCKFILES = [
        'composer.lock' => 'composer',
        'package-lock.json' => 'npm',
        'npm-shrinkwrap.json' => 'npm',
        'yarn.lock' => 'yarn',
        'pnpm-lock.yaml' => 'pnpm',
        'bun.lockb' => 'bun',
        'bun.lock' => 'bun',
        'poetry.lock' => 'poetry',
        'Pipfile.lock' => 'pipenv',
        'uv.lock' => 'uv',
        'requirements.txt' => 'pip',
    ];

    /**
     * @param  list<string>  $treePaths
     * @return array{tooling: list<string>, package_managers: list<string>}
     */
    public static function detect(array $treePaths): array
    {
        $rootFiles = array_values(array_filter($treePaths, fn (string $p) => ! str_contains($p, '/')));
        $tooling = [];
        $packageManagers = [];

        foreach (self::CONFIG_FILES as $tool => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($rootFiles as $file) {
                    if (PathMatcher::matchesGlob($file, $pattern)) {
                        $tooling[] = $tool;

                        continue 3;
                    }
                }
            }
        }

        foreach ($treePaths as $path) {
            if (preg_match('~^\.github/workflows/[^/]+\.ya?ml$~', $path) === 1) {
                $tooling[] = 'github-actions';

                break;
            }
        }

        foreach ($rootFiles as $file) {
            if (isset(self::LOCKFILES[$file])) {
                $packageManagers[] = self::LOCKFILES[$file];
            }
        }

        return [
            'tooling' => array_values(array_unique($tooling)),
            'package_managers' => array_values(array_unique($packageManagers)),
        ];
    }
}
