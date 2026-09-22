<?php

/*
|--------------------------------------------------------------------------
| Sentinel Slop configuration
|--------------------------------------------------------------------------
|
| Everything that governs how a repository is fetched, checked, analysed,
| scored and turned into prompts. Values here are read by the Laravel side
| (jobs, services, UI) and passed into App\Scanning as plain arrays/DTOs.
| App\Scanning never reads this file directly.
|
*/

return [

    /*
    | Where fetched repository files live while a scan runs. Each scan gets
    | its own subdirectory named after the scan's UUID. The directory is
    | deleted when the scan finishes (success or failure) and the sweeper
    | removes anything older than `stale_scan_minutes`.
    */
    'scan_storage_path' => env('SENTINEL_SCAN_STORAGE_PATH', storage_path('app/scans')),
    'stale_scan_minutes' => (int) env('SENTINEL_STALE_SCAN_MINUTES', 60),

    /*
    | Fetch and preflight limits. Enforced while downloading, not afterwards.
    */
    'limits' => [
        'max_total_bytes' => (int) env('SENTINEL_MAX_TOTAL_BYTES', 50 * 1024 * 1024),
        'max_file_count' => (int) env('SENTINEL_MAX_FILE_COUNT', 5000),
        'max_single_file_bytes' => (int) env('SENTINEL_MAX_SINGLE_FILE_BYTES', 1024 * 1024),
    ],

    /*
    | Directories that are never downloaded or analysed. Matched against any
    | path segment, so "vendor" also matches "packages/foo/vendor".
    */
    'skipped_directories' => [
        'vendor',
        'node_modules',
        'dist',
        'build',
        '.git',
        'bower_components',
        '.next',
        '.nuxt',
        '.output',
        'coverage',
        'storage/framework',
        'public/build',
        'public/hot',
        // Sentinel Slop's own throwaway test key pair. Never a real credential.
        'tests/Fixtures/keys',
    ],

    /*
    | Files treated as generated or minified and therefore skipped.
    | Glob patterns matched against the basename.
    */
    'generated_file_patterns' => [
        '*.min.js',
        '*.min.css',
        '*.map',
        '*.lock',
        'package-lock.json',
        'yarn.lock',
        'pnpm-lock.yaml',
        'composer.lock',
        '_ide_helper.php',
        '_ide_helper_models.php',
        '.phpstorm.meta.php',
    ],

    /*
    | Queue settings for the scan pipeline. The pipeline always runs on its
    | own queue so it can be moved to a dedicated worker server.
    */
    'queue' => [
        'connection' => env('SENTINEL_SCANS_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
        'name' => env('SENTINEL_SCANS_QUEUE', 'scans'),
        'job_timeout_seconds' => (int) env('SENTINEL_JOB_TIMEOUT', 900),
    ],

    /*
    | Per-user rate limiting on starting scans.
    */
    'rate_limit' => [
        'scans_per_hour' => (int) env('SENTINEL_SCANS_PER_HOUR', 5),
    ],

    /*
    | External analyser binaries. PHP-based tools ship with Sentinel Slop
    | via Composer, Node-based tools via npm. Semgrep and gitleaks are
    | system binaries; see README for installation. Paths are overridable
    | so production can point at absolute locations.
    */
    'tools' => [
        'php' => env('SENTINEL_PHP_BINARY', PHP_BINARY),
        'node' => env('SENTINEL_NODE_BINARY', 'node'),
        'phpstan' => env('SENTINEL_PHPSTAN_PATH', base_path('vendor/phpstan/phpstan/phpstan')),
        'pint' => env('SENTINEL_PINT_PATH', base_path('vendor/laravel/pint/builds/pint')),
        'eslint' => env('SENTINEL_ESLINT_PATH', base_path('node_modules/eslint/bin/eslint.js')),
        'jscpd' => env('SENTINEL_JSCPD_PATH', base_path('node_modules/jscpd/bin/jscpd')),
        'semgrep' => env('SENTINEL_SEMGREP_BINARY', 'semgrep'),
        'gitleaks' => env('SENTINEL_GITLEAKS_BINARY', 'gitleaks'),
        'timeout_seconds' => (int) env('SENTINEL_TOOL_TIMEOUT', 300),
    ],

    /*
    | Bundled resources. Analysers only ever load configuration from these
    | locations, never from the scanned repository.
    */
    'paths' => [
        'analyser_configs' => resource_path('analyser-configs'),
        'semgrep_rules' => resource_path('semgrep'),
        'rulesets' => resource_path('rulesets'),
        'prompts' => resource_path('prompts'),
    ],

    /*
    | Slop score. See CLAUDE.md for the formula.
    */
    'score' => [
        'weights' => [
            'critical' => 25,
            'high' => 10,
            'medium' => 4,
            'low' => 1,
            'info' => 0,
        ],
        'scale' => (float) env('SENTINEL_SCORE_SCALE', 1.0),
        'critical_cap' => (int) env('SENTINEL_SCORE_CRITICAL_CAP', 40),
    ],

    /*
    | LLM synthesis. Provider and model map straight onto Prism.
    */
    'synthesis' => [
        'provider' => env('SENTINEL_LLM_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_LLM_MODEL', 'claude-sonnet-5'),
        // Models a user may pick per scan. Comma-separated in SENTINEL_LLM_MODELS; the default model is always allowed.
        'models' => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) env('SENTINEL_LLM_MODELS', env('SENTINEL_LLM_MODEL', 'claude-sonnet-5'))))))),
        'token_budget' => (int) env('SENTINEL_LLM_TOKEN_BUDGET', 24000),
        'max_snippet_lines' => 6,
        'target_editors' => ['claude_code', 'cursor'],
    ],

    /*
    | GitHub usernames allowed to open the Horizon dashboard.
    */
    'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SENTINEL_ADMIN_GITHUB_USERNAMES', ''))))),
];
