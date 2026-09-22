<?php

declare(strict_types=1);

namespace App\Scanning\Detect;

final class PackageJsonParser
{
    private const FRAMEWORKS = [
        'react' => 'react',
        'next' => 'next',
        'vue' => 'vue',
        'nuxt' => 'nuxt',
        'svelte' => 'svelte',
        '@sveltejs/kit' => 'sveltekit',
        '@angular/core' => 'angular',
        'express' => 'express',
        'fastify' => 'fastify',
        '@nestjs/core' => 'nestjs',
        'koa' => 'koa',
        'hono' => 'hono',
        'astro' => 'astro',
        '@remix-run/react' => 'remix',
        '@inertiajs/react' => 'inertia',
        '@inertiajs/vue3' => 'inertia',
        'alpinejs' => 'alpine',
        'gatsby' => 'gatsby',
        'electron' => 'electron',
        'react-native' => 'react-native',
        'expo' => 'expo',
        'solid-js' => 'solid',
        'preact' => 'preact',
    ];

    private const TOOLING = [
        'typescript' => 'typescript',
        'eslint' => 'eslint',
        'prettier' => 'prettier',
        '@biomejs/biome' => 'biome',
        'jest' => 'jest',
        'vitest' => 'vitest',
        'mocha' => 'mocha',
        '@playwright/test' => 'playwright',
        'cypress' => 'cypress',
        'tailwindcss' => 'tailwind',
        'vite' => 'vite',
        'webpack' => 'webpack',
        'esbuild' => 'esbuild',
        'tsup' => 'tsup',
        'turbo' => 'turborepo',
        'nx' => 'nx',
        'husky' => 'husky',
        'lint-staged' => 'lint-staged',
        'storybook' => 'storybook',
    ];

    public static function parse(string $json): ManifestData
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return new ManifestData('npm');
        }

        $dependencies = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            foreach (array_keys(is_array($data[$section] ?? null) ? $data[$section] : []) as $package) {
                $dependencies[] = (string) $package;
            }
        }

        return ManifestData::fromDependencies('npm', array_values(array_unique($dependencies)), self::FRAMEWORKS, self::TOOLING);
    }
}
