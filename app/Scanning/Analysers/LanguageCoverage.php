<?php

declare(strict_types=1);

namespace App\Scanning\Analysers;

use App\Scanning\Data\Stack;

/**
 * Which languages in a repository had a language-specific analyser and which
 * got structural analysis only. Shown on the results page and told to the
 * reviewer, so a Python user is never handed three phases about their
 * JavaScript without knowing why.
 */
final class LanguageCoverage
{
    /** Language (as the stack names it) => language-specific analysers that ran on it. */
    private const ANALYSERS = [
        'PHP' => ['PHPStan', 'Pint', 'php-parser heuristics', 'Semgrep PHP rules'],
        'JavaScript' => ['ESLint', 'Semgrep JS/TS rules'],
        'TypeScript' => ['ESLint', 'Semgrep JS/TS rules'],
        'Python' => ['Ruff'],
    ];

    /** Languages that are markup, styling or configuration: no analyser is expected. */
    private const NOT_CODE = ['CSS', 'SCSS', 'Sass', 'Less', 'HTML', 'Blade', 'Twig', 'Markdown', 'JSON', 'YAML', 'TOML', 'XML', 'SVG', 'Dockerfile', 'Shell', 'PowerShell', 'Batchfile', 'Makefile', 'HCL', 'Nix', 'CMake', 'Procfile', 'Jinja', 'Mako', 'Smarty', 'Handlebars', 'Pug', 'Text', 'INI'];

    /** Applied to every repository regardless of language. */
    public const UNIVERSAL = ['repository profile and absence checks', 'jscpd duplication', 'gitleaks secrets'];

    /**
     * @return array{analysed: array<string, list<string>>, structural_only: list<string>, universal: list<string>}
     */
    public static function describe(Stack $stack): array
    {
        $analysed = [];
        $structuralOnly = [];
        foreach ($stack->languagePercentages() as $language => $share) {
            if ($share < 1 || in_array($language, self::NOT_CODE, true)) {
                continue;
            }
            if (isset(self::ANALYSERS[$language])) {
                $analysed[$language] = self::ANALYSERS[$language];
            } else {
                $structuralOnly[] = $language;
            }
        }

        return ['analysed' => $analysed, 'structural_only' => $structuralOnly, 'universal' => self::UNIVERSAL];
    }

    /**
     * One sentence for prompts and pages.
     */
    public static function sentence(Stack $stack): string
    {
        $coverage = self::describe($stack);
        $parts = [];
        foreach ($coverage['analysed'] as $language => $tools) {
            $parts[] = $language.' ('.implode(', ', $tools).')';
        }
        $sentence = $parts !== [] ? 'Language-specific analysers ran for '.implode('; ', $parts).'.' : 'No language-specific analyser ran.';
        if ($coverage['structural_only'] !== []) {
            $sentence .= ' '.implode(', ', $coverage['structural_only']).' had structural analysis only (the profile, duplication and secrets scanning): findings in those files are shape and absence, never line-level correctness.';
        }

        return $sentence;
    }
}
