<?php

declare(strict_types=1);

namespace App\Scanning\Synthesis;

use App\Scanning\Data\Stack;

/**
 * Picks the curated markdown rulesets that apply to a stack.
 */
final class RulesetLoader
{
    public function __construct(private readonly string $rulesetsPath) {}

    /**
     * @return list<string> Ruleset names in the order they should be presented.
     */
    public function namesFor(Stack $stack): array
    {
        $names = [];

        if ($stack->hasPhp()) {
            $names[] = 'php';
            if ($stack->hasFramework('laravel') || $stack->hasFramework('lumen')) {
                $names[] = 'laravel';
            }
        }

        if ($stack->hasJavaScript()) {
            $names[] = 'javascript';
            if ($stack->hasTypeScript()) {
                $names[] = 'typescript';
            }
            foreach (['react', 'next', 'remix', 'gatsby', 'react-native', 'expo'] as $framework) {
                if ($stack->hasFramework($framework)) {
                    $names[] = 'react';
                    break;
                }
            }
        }

        return array_values(array_filter($names, fn (string $name) => is_file($this->path($name))));
    }

    /**
     * @return array<string, string> name => markdown
     */
    public function load(Stack $stack): array
    {
        $rulesets = [];
        foreach ($this->namesFor($stack) as $name) {
            $rulesets[$name] = (string) file_get_contents($this->path($name));
        }

        return $rulesets;
    }

    private function path(string $name): string
    {
        return rtrim($this->rulesetsPath, '/').'/'.$name.'.md';
    }
}
