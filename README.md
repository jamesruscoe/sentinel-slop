# Sentinel Slop

Scans GitHub repositories for AI-generated "slop", detects the stack, applies curated best practices and generates phased fix-it prompts for Claude Code and Cursor.

**Your code is only read, never run.** Nothing from a scanned repository is executed, installed, built, imported or loaded as configuration. Fetched files are deleted after every scan. Short, redacted snippets are sent to an AI provider to generate prompts.

See `CLAUDE.md` for architecture and conventions. This README covers local setup; the Forge deployment guide is added in phase 6.

## Local setup (Windows, Laravel Herd)

Requirements:

- Laravel Herd with PHP 8.4 (`herd use 8.4`) and Node 24 (Herd's nvm). Make both your shell defaults.
- MySQL on 127.0.0.1:3306 (Herd Pro service or a standalone install) with a database `sentinel_slop`.
- Redis on 127.0.0.1:6379. Recommended: [Memurai Developer](https://www.memurai.com/get-memurai) (`winget install Memurai.MemuraiDeveloper`).
- Semgrep and gitleaks binaries (needed from phase 3; see below).

```
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
cp .env.example .env
php artisan key:generate
php artisan reverb:install      # fills REVERB_* in .env
php artisan migrate
npm install
npm run build
```

Horizon requires `ext-pcntl`/`ext-posix`, which do not exist on Windows, hence the ignore flags. Locally, run plain queue workers instead of Horizon:

```
php artisan queue:work redis --queue=scans
php artisan queue:work redis
php artisan reverb:start
```

Serve the site as http://sentinel-slop.test: Herd → Sites → Link this folder (or create a junction from `%USERPROFILE%\.config\herd\config\valet\Sites\sentinel-slop` to the project).

## Creating the GitHub App

1. GitHub → Settings → Developer settings → GitHub Apps → New GitHub App.
2. Homepage URL: `http://sentinel-slop.test`. Callback URL: `http://sentinel-slop.test/auth/github/callback`. Tick "Request user authorization (OAuth) during installation" off; keep "Expire user authorization tokens" on.
3. Setup URL: `http://sentinel-slop.test/github/setup`, tick "Redirect on update".
4. Webhook: active, URL `<public URL from herd share>/webhooks/github`, secret: a long random string.
5. Permissions: Repository → Contents: Read-only, Metadata: Read-only. Nothing else.
6. Subscribe to events: Installation, Installation repositories.
7. Where can this app be installed: Any account.
8. After creating: note the App ID and Client ID, generate a client secret, and generate a private key. Save the `.pem` as `storage/keys/github-app.pem` (gitignored).
9. Fill the `GITHUB_APP_*` values in `.env` (each is documented in `.env.example`).

Run `herd share` to get a public URL for webhooks during development and paste it into the app's webhook URL.

## Analyser binaries (phase 3 onward)

PHPStan, Pint, ESLint and jscpd are installed with Composer/npm. Semgrep and gitleaks are system binaries:

- gitleaks: `winget install Gitleaks.Gitleaks` (or download from the GitHub releases page) and make sure it is on `PATH`, or set `SENTINEL_GITLEAKS_BINARY`.
- Semgrep: needs Python 3.9+. `pip install semgrep`. Native Windows support is beta; if it fails, run it under WSL and point `SENTINEL_SEMGREP_BINARY` at a wrapper script.

Check everything with `php artisan sentinel:doctor`.

## LLM configuration

Prompt synthesis goes through [Prism](https://prismphp.com). Set `SENTINEL_LLM_PROVIDER` (default `anthropic`), `ANTHROPIC_API_KEY`, and optionally `SENTINEL_LLM_MODELS` (comma-separated model ids users may pick per scan; the default `SENTINEL_LLM_MODEL` is always allowed). Only redacted findings, the detected stack and the bundled rulesets are ever sent.

**Windows Defender note:** `tests/Fixtures/repos/backdoor-samples` and the temp copies made under `%TEMP%\sentinel-tests` contain webshell-like text and may be quarantined mid-test. Add both folders as Defender exclusions before running the suite.

## Tests and static analysis

```
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
