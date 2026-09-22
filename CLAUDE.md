# Sentinel Slop

Laravel app that scans GitHub repositories for AI-generated "slop", detects the stack, applies curated best-practice rulesets and generates phased fix-it prompts for agentic editors (Claude Code, Cursor).

## Core principle (overrides everything else)

Sentinel Slop only READS user code. Nothing from a scanned repository is ever executed, installed, built, imported or loaded as configuration, directly or through an analyser. The only code that runs is Sentinel Slop's own code and its bundled analyser configs in `resources/analyser-configs/` and `resources/semgrep/`. Any change that would let user-supplied code or config execute, or influence how an analyser runs, is a bug.

Concrete consequences:

- Repos are fetched file-by-file through the GitHub Git Trees API. No `git clone`. Symlinks (mode 120000) and submodules (160000) are never written to disk.
- `vendor/`, `node_modules/`, `dist/`, `build/` and friends (see `config/sentinel.php` → `skipped_directories`) are never downloaded, so no `vendor/autoload.php` can exist in a scan directory.
- Analysers run as argv-array processes (never shell strings) via the `ProcessRunner` interface, with a timeout, and are always given an explicit bundled config plus the tool's "do not discover config" flag.
- Larastan is NOT used when scanning user repos because it boots a Laravel application. Plain PHPStan with our own neon only. Larastan is only used on Sentinel Slop's own code.
- Fetched files are deleted after every scan (success or failure) and a scheduled sweeper removes anything older than one hour.
- Installation tokens are minted per scan and never logged or persisted. `App\Services\GitHub\InstallationToken` refuses to be serialised.
- Secret values found by gitleaks are redacted before storage and before anything is sent to the LLM.

## Stack

Laravel 13, PHP 8.4, Livewire 4 (class-based components in `app/Livewire`), Tailwind 4 via Vite, Horizon (production only), Reverb, Socialite (GitHub), knplabs/github-api, Prism (LLM, default Anthropic), Pest 5, Larastan + Pint.

Local development uses Laravel Herd on Windows at http://sentinel-slop.test. Production target is Laravel Forge (Ubuntu). Nothing may depend on Herd at runtime.

## Architecture

```
app/
  Enums/            ScanStatus (Laravel-side pipeline state)
  Models/           User, Installation, Repository, Scan, Finding, Prompt, RulesFile, WebhookDelivery
  Policies/         RepositoryPolicy, ScanPolicy — users only see repos their installations cover
  Services/GitHub/  GitHubAppJwt, GitHubAppApi (+Knp adapter), InstallationTokenService,
                    InstallationSyncService, WebhookHandler, WebhookSignatureVerifier
  Jobs/Scan/        ScanStageJob base + one job per stage, all on the `scans` queue
  Services/Scanning/ ScanDispatcher, ScanPipeline, ScanFailureHandler, ScanWorkspaceFactory, ContentSourceResolver
  Scanning/         framework-free scanning core (see below)
    Contracts/      Analyser, Heuristic, ProcessRunner, GitHubContentSource, RepositoryFetcher
    Data/           Finding, FindingCollection, Stack, SkippedFile, FetchResult, PreflightResult, Process*, limits
    Fetch/          GitHubTreeFetcher, ScanWorkspace, KnpGitHubContentSource
    Preflight/      PreflightChecker, BinaryDetector, GeneratedFileDetector
    Detect/         StackDetector + manifest parsers (data only) + ToolingDetector
    Analysers/      ProcessAnalyser base, PhpStan/Pint/Eslint/Jscpd/Semgrep/Gitleaks analysers, registries, CategoryMapper
    Heuristics/     narrating comments, swallowed exceptions, hallucinated deps, placeholders, near-duplicates, oversized units
    Process/        ToolLocator (binary discovery), EnvironmentAllowlist (scrubbed child env)
    Normalise/      FindingNormaliser, FindingDeduplicator, SecretRedactor
    Support/        PathGuard, PathMatcher, FileWalker
config/sentinel.php limits, skip lists, tool paths, score weights, synthesis settings
resources/analyser-configs/  bundled tool configs (the ONLY configs analysers ever load)
resources/semgrep/           bundled Semgrep rules (malware/, quality/, slop/)
resources/rulesets/          curated best-practice markdown per stack
resources/prompts/           Blade templates for LLM prompts and rules files
```

### `App\Scanning` rules

- Must not depend on HTTP, Livewire, Eloquent models, facades or auth. It receives a path plus plain config arrays/DTOs and returns DTOs. It will be extracted into a Composer package.
- `App\Scanning\Enums` (Severity, FindingCategory, TargetEditor, SkipReason) may be used by models; the reverse is not allowed.
- Analysers implement `Analyser` (`supports(Stack)`, `run(string $path): FindingCollection`); heuristics implement `Heuristic`; processes go through `ProcessRunner`.
- Scanning code never reads app secrets. Scan jobs must work with a stripped `.env` (DB, Redis, GitHub App credentials, LLM key).

### Scan pipeline

`ScanStatus` order: queued → fetching → preflight → detecting → analysing → heuristics → normalising → scoring → synthesising → complete, or failed. One job per stage, chained on the `scans` queue via `ScanDispatcher`, each updating status and broadcasting `ScanProgressed` on private channel `scans.{uuid}`. The chain's `catch` hands off to `ScanFailureHandler` (user-safe message, workspace deleted). `CleanupScan` runs before `CompleteScan` so a scan is only complete once its files are gone.

Stages pass data through JSON artifacts in the workspace's `work/` dir (`fetch`, `preflight`, `stack`, `findings/<tool>`, `findings-normalised`); only normalised findings reach the database. Workspace layout: `{scan_storage_path}/{uuid}/repo` (files) and `/work` (artifacts).

Fetch rules: tree entries with mode 120000 (symlink) or type commit (submodule) are recorded as skipped and never written; only modes 100644/100755 are downloaded; paths go through `PathGuard` (no `..`, absolute, backslash, NUL, `.git`); skipped directories are not downloaded at all; limits are checked against tree sizes before any blob is fetched and again on the real bytes. Preflight fails the scan on symlinks, path escapes or limits, and deletes binaries (by content), executables (magic bytes), generated/minified files and dependency dirs from the workspace, recording each in `scans.skipped_files` (`{total, counts, entries[≤500], truncated}`).

### Analysers (phase 3)

Every tool runs through `ProcessRunner` (Laravel's Process facade, argv arrays, timeout, `EnvironmentAllowlist` strips all inherited env except PATH/TEMP/HOME-style basics) with cwd set to `work/<tool>/` inside the workspace, never the repo. Config discovery is disabled per tool:

| Tool | Flags | Canary test |
|---|---|---|
| PHPStan | `--configuration=<bundled neon>`; cwd is not the repo, so `vendor/autoload.php`/`phpstan.neon`/`composer.json` are never seen; no bootstrapFiles, no Larastan | `phpstan.neon`, `.dist` variants, bootstrap file, runtime `vendor/autoload.php` |
| Pint | `--test --config=<bundled pint.json> --cache-file=<work>` | `pint.json` excludes, `.php-cs-fixer*.php` |
| ESLint | `--no-config-lookup --config <bundled mjs> --no-inline-config`; cwd = workspace root (ESLint's base path is cwd), target `repo` | `eslint.config.*`, `.eslintrc*`, `.eslintignore`, package.json `eslintConfig`, `/* eslint-disable */` |
| jscpd | `--config <bundled json>` (`gitignore: false`) | `.jscpd.json` ignore-all + custom reporter, package.json `jscpd` |
| gitleaks | `dir --config <bundled toml> --gitleaks-ignore-path <bundled dir> --ignore-gitleaks-allow --redact=100` | `.gitleaks.toml` allowlist-all, `.gitleaksignore`, `gitleaks:allow` |
| Semgrep | `--config <bundled rules dir> --metrics=off --no-git-ignore --disable-nosem --x-ignore-semgrepignore-files` | `.semgrepignore`, `.semgrep.yml`, `nosemgrep` |

`tests/Feature/Scanning/MaliciousConfigsTest.php` runs each analyser against `tests/Fixtures/repos/malicious-configs`, whose configs write `CANARY_*` files if loaded and try to suppress findings. Any canary file or missing finding fails the build.

Stage wiring (in `ScanningServiceProvider`): preflight runs Semgrep `malware` rules + gitleaks (hits → critical); RunAnalysers runs PHPStan, Pint, ESLint, Semgrep `quality`, jscpd; RunSlopHeuristics runs the php-parser heuristics + Semgrep `slop` rules. Semgrep rule ids are prefixed by the rules path on output; `SemgrepAnalyser` strips back to `sentinel.…`.

PHPStan without the repo's vendor: level 5, unknown-symbol identifiers (`class.notFound`, `*.notFound`, `*.nonObject`, `missingType.*`) are ignored by identifier in the bundled neon so members resolved through unknown parents (Eloquent, controllers) do not flood results; `return.type`, `argument.type`, dead-code and syntax errors still surface.

`php artisan sentinel:doctor` checks every binary and prints versions. Windows Defender quarantines textbook webshell text: keep malware fixtures split into small, non-signature-like samples and exclude the repo and `%TEMP%\sentinel-tests` from real-time scanning when running the suite.

Per-scan LLM model: `scans.llm_model` (validated against `sentinel.synthesis.models`); null means the configured default. `php artisan sentinel:scan owner/name --model=...` queues a scan from the CLI.

### GitHub integration

- Login: Socialite `github` driver using the GitHub App's OAuth client id/secret. Identity only; no user token is stored.
- Repo access: GitHub App with `contents: read`, `metadata: read`. Users install it at `/github/install`; GitHub redirects to `/github/setup`.
- Ownership: the signed `installation.created` webhook is the source of truth. `sender.id` ↔ `users.github_id`. Org installs belong to the installing user only (MVP).
- `/github/setup` claims personal installations directly via the API when the webhook has not landed yet; otherwise it shows a refreshing "waiting" page.
- Webhooks: `POST /webhooks/github`, CSRF-exempt, HMAC verified by `VerifyGitHubWebhookSignature`, idempotent via `webhook_deliveries`.

### Slop score (phase 4)

```
penalty = Σ weight[severity]              weights in config/sentinel.php (critical 25, high 10, medium 4, low 1, info 0)
density = penalty / max(loc, 1) * 1000    penalty per thousand lines of analysed code
score   = max(0, 100 - round(density * scale))
if any malware/secrets finding: score = min(score, critical_cap)   (default 40)
```

## Conventions

- Controllers are thin: validate → service → redirect/view. Business logic lives in `app/Services` and `app/Scanning`.
- Models use `#[Fillable]` attributes and the `casts()` method. Status/category/severity columns are strings in the DB with PHP enum casts. `Model::shouldBeStrict()` is on outside production: eager-load relations, no missing attributes.
- Scans are addressed by `uuid` in URLs and temp dir names; the integer `id` stays the primary key.
- Tests are Pest. Feature tests use `RefreshDatabase` on in-memory SQLite. Fake GitHub via `Tests\Support\FakeGitHubAppApi` bound to `GitHubAppApi`. Never hit the network in tests.
- Code must pass `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` (level 6, Larastan) before a phase is done.
- Windows note: Horizon needs `ext-pcntl`/`ext-posix`, so it does not run locally. Install with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` and use `queue:work` locally. Horizon runs on Forge.

## Running things

```
# PHP 8.4 from Herd, Node 24 from Herd's nvm (set as shell defaults)
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
npm install && npm run build            # or npm run dev
php artisan migrate
php artisan queue:work redis --queue=scans   # scan pipeline worker (local)
php artisan queue:work redis                 # default queue worker (local)
php artisan reverb:start                     # websockets for live progress
herd share                                   # public URL for GitHub webhooks
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Site: http://sentinel-slop.test (junction in Herd's Sites directory points at this folder). MySQL database `sentinel_slop`, Redis via Memurai on 6379.

## Build phases

1. Foundation (done): Herd, packages, GitHub login, GitHub App install + webhooks, models, migrations, config, this file.
2. Scanning core (done): `App\Scanning` interfaces/DTOs, FetchRepository, PreflightCheck, stack detection, normalisation, cleanup, fixture repos in `tests/Fixtures/repos/` (hand-written stubs only; vendor/node_modules there are gitignored and created at test time).
3. Analysers (done): ProcessRunner, bundled configs, tool runners, slop heuristics, malicious-config canary tests, `sentinel:doctor`.
4. Synthesis: rulesets, slop score, redaction, Prism, prompt and rules-file generation.
5. UI: landing, dashboard, live scan page, results, history.
6. Hardening and deploy prep: rate limiting, failure handling, sweeper, Horizon supervisors, README for Forge.
