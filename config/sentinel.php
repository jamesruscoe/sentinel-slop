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
        'scans_per_user_per_hour' => (int) env('SENTINEL_SCANS_PER_USER_PER_HOUR', 10),
        'max_total_bytes' => (int) env('SENTINEL_MAX_TOTAL_BYTES', 50 * 1024 * 1024),
        'max_file_count' => (int) env('SENTINEL_MAX_FILE_COUNT', 5000),
        'max_single_file_bytes' => (int) env('SENTINEL_MAX_SINGLE_FILE_BYTES', 1024 * 1024),
        // composer.lock / package-lock.json / yarn.lock / pnpm-lock.yaml are kept (they resolve imports exactly) up to this size.
        'max_lockfile_bytes' => (int) env('SENTINEL_MAX_LOCKFILE_BYTES', 8 * 1024 * 1024),
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
        'jscpd' => env('SENTINEL_JSCPD_PATH', base_path('node_modules/jscpd/run-jscpd.js')),
        'semgrep' => env('SENTINEL_SEMGREP_BINARY', 'semgrep'),
        // The Semgrep release the bundled flags and rules were verified against. sentinel:doctor warns on mismatch.
        'semgrep_version' => env('SENTINEL_SEMGREP_VERSION', '1.177.0'),
        'gitleaks' => env('SENTINEL_GITLEAKS_BINARY', 'gitleaks'),
        'timeout_seconds' => (int) env('SENTINEL_TOOL_TIMEOUT', 300),
        'phpstan_memory_limit' => env('SENTINEL_PHPSTAN_MEMORY', '1G'),
    ],

    /*
    | Thresholds for analysers and slop heuristics.
    */
    'analysis' => [
        'max_file_lines' => (int) env('SENTINEL_MAX_FILE_LINES', 600),
        'max_function_lines' => (int) env('SENTINEL_MAX_FUNCTION_LINES', 80),
        'jscpd' => [
            'min_lines' => 10,
            'min_tokens' => 70,
        ],
        'near_duplicate_similarity' => 0.85,
        'narrating_comment_overlap' => 0.6,
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
        // Only critical/high/medium findings drive the curve: density = their weighted points
        // divided by sqrt(KLOC) (floor 1 KLOC), score = 100 * exp(-(density / scale) ^ exponent).
        // scale is the density that scores ~37. See CLAUDE.md for a worked table.
        'curve' => [
            'scale' => (float) env('SENTINEL_SCORE_CURVE_SCALE', 65),
            'exponent' => (float) env('SENTINEL_SCORE_CURVE_EXPONENT', 1.4),
        ],
        // Low findings (mostly style) cost low_points each, capped at low_cap points in total.
        'low_points' => (float) env('SENTINEL_SCORE_LOW_POINTS', 0.1),
        'low_cap' => (int) env('SENTINEL_SCORE_LOW_CAP', 5),
        'critical_cap' => (int) env('SENTINEL_SCORE_CRITICAL_CAP', 40),
        // Inline suppression comments per thousand lines cost this many points each, up to the cap.
        'suppression_weight' => (float) env('SENTINEL_SCORE_SUPPRESSION_WEIGHT', 2.0),
        'suppression_cap' => (int) env('SENTINEL_SCORE_SUPPRESSION_CAP', 20),
    ],

    /*
    | LLM synthesis. Provider and model map straight onto Prism.
    */
    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Two columns hold snippets of users' code: scans.synthesis_payload (the
    | exact prompts sent to the LLM) and findings.snippet. One policy covers
    | both once a scan is older than `days`: `purge` removes the code (nulls
    | the payload and every snippet), `truncate` keeps the payload's headers
    | (stack, score, category counts) but drops its findings section and
    | nulls every snippet, `retain` keeps everything. Applied daily by
    | `sentinel:prune`.
    */
    'retention' => [
        'code' => env('SENTINEL_RETAIN_CODE', 'purge'),
        'days' => (int) env('SENTINEL_RETAIN_CODE_DAYS', 30),
    ],

    'synthesis' => [
        // Off skips the LLM call entirely (findings and score still complete); used by sentinel:scan-fixture --no-synthesis.
        'enabled' => (bool) env('SENTINEL_SYNTHESIS_ENABLED', true),
        'provider' => env('SENTINEL_LLM_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_LLM_MODEL', 'claude-sonnet-5'),
        // Models a user may pick per scan. Comma-separated in SENTINEL_LLM_MODELS; the default model is always allowed.
        'models' => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) env('SENTINEL_LLM_MODELS', env('SENTINEL_LLM_MODEL', 'claude-sonnet-5'))))))),
        // Findings payload budget (input). The synthesiser also caps it so that
        // system prompt + findings + max_output_tokens always fit in context_window.
        'token_budget' => (int) env('SENTINEL_LLM_TOKEN_BUDGET', 24000),
        // A 23-finding fixture produced ~12k output tokens; the model does not reliably honour the word limit, so leave headroom.
        'max_output_tokens' => (int) env('SENTINEL_LLM_MAX_OUTPUT_TOKENS', 32000),
        'context_window' => (int) env('SENTINEL_LLM_CONTEXT_WINDOW', 200000),
        // Non-streaming: the whole reply is generated before a byte arrives, and 15-20k output tokens take several minutes.
        'timeout_seconds' => (int) env('SENTINEL_LLM_TIMEOUT', 600),
        'max_snippet_lines' => 6,
        'max_findings' => (int) env('SENTINEL_LLM_MAX_FINDINGS', 150),
        // A rule firing more than this many times is sent as one aggregated finding with a count and example paths.
        'aggregate_threshold' => (int) env('SENTINEL_LLM_AGGREGATE_THRESHOLD', 5),
        'target_editors' => ['claude_code', 'cursor'],
    ],

    /*
    | GitHub usernames allowed to open the Horizon dashboard.
    */
    'admins' => array_values(array_filter(array_map('trim', explode(',', (string) env('SENTINEL_ADMIN_GITHUB_USERNAMES', ''))))),
];
