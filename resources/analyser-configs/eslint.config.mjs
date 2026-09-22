// Sentinel Slop's bundled ESLint config. This is the ONLY config ESLint ever
// loads during a scan (--no-config-lookup --no-inline-config). Non-type-aware
// rules only: the scanned repository's dependencies are never installed.
import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default [
    {
        ignores: ['**/node_modules/**', '**/vendor/**', '**/dist/**', '**/build/**', '**/*.min.js', '**/coverage/**'],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['**/*.{js,mjs,cjs,jsx,ts,tsx,mts,cts}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
        },
        rules: {
            // Globals are unknown without the project's environment; do not report them.
            'no-undef': 'off',
            'no-unused-vars': 'off',
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/no-empty-function': 'warn',
            '@typescript-eslint/no-require-imports': 'off',
            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-var': 'error',
            'prefer-const': 'warn',
            'no-empty': ['error', { allowEmptyCatch: false }],
            'no-eval': 'error',
            'no-implied-eval': 'error',
            'no-new-func': 'error',
            'no-debugger': 'error',
            'no-alert': 'warn',
            'max-lines': ['warn', { max: 600, skipBlankLines: true, skipComments: true }],
            'max-lines-per-function': ['warn', { max: 80, skipBlankLines: true, skipComments: true }],
            complexity: ['warn', 20],
            'max-depth': ['warn', 4],
            'max-params': ['warn', 6],
        },
    },
];
