# Sentinel Slop

Scans GitHub repositories for AI-generated "slop", detects the stack, applies curated best practices and generates phased fix-it prompts for Claude Code and Cursor.

**Your code is only read, never run.** Nothing from a scanned repository is executed, installed, built, imported or loaded as configuration. Fetched files are deleted after every scan. Short, redacted snippets are sent to an AI provider to generate prompts.

See `CLAUDE.md` for architecture and conventions. This README covers local setup and the production deployment on AWS (ECS Fargate, provisioned with Terraform).

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

## Using it

Sign in with GitHub, install the app on the repositories you want scanned, then press **Scan** next to a repository. The scan page updates live (Reverb, with polling as a fallback) and, once complete, shows the slop score, the detected stack, the findings, the five fix-it prompts for Claude Code or Cursor, a rules file to keep in the repository, and exactly what was sent to the AI provider. Scan history is under **Scans**.

## Tests and static analysis

```
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## Deployment (AWS, ECS Fargate Spot, Terraform)

Production is AWS, provisioned with Terraform in `infra/`, deployed by GitHub Actions, on the same pattern as Dog Desk: one task, no load balancer, no managed database, about $20 a month. Forge is not used.

### Shape

One container image, one ECS service, one Fargate Spot task (0.5 vCPU / 2 GB) running `CONTAINER_ROLE=all`. The entrypoint runs `php artisan migrate --force` and `sentinel:recover-interrupted --force`, then supervisord starts nginx + php-fpm (port 8080), a `scans` queue worker, a `default` queue worker and `schedule:work` (`docker/supervisord-all.conf`).

Around it:

- **SQLite on EFS** (`/mnt/data/database.sqlite`, encrypted, no backups (the data can be recreated), an access point that makes every write uid 33). The queue (`QUEUE_CONNECTION=database`), sessions and the cache (`file`) need no Redis. The rollback journal stays the default because WAL is unsafe on a network filesystem; `DB_BUSY_TIMEOUT=15000` makes the five processes wait for a lock rather than fail.
- **CloudFront** terminates TLS with a us-east-1 ACM certificate and forwards every header, cookie and query string to `origin.<domain>` over HTTP on 8080. Nothing is cached except `/build/*`.
- **The origin-dns Lambda** (Dog Desk's): EventBridge fires on every task reaching RUNNING, and the Lambda UPSERTs `origin.<domain>` to the task's public IP. A deploy or Spot replacement costs a minute or two of 502s.
- The task's security group opens 8080 to CloudFront's origin-facing prefix list only. That is why nginx can tell PHP `HTTPS=on` and why `TRUSTED_PROXIES=*` is safe. CloudFront sends no `X-Forwarded-Proto`.
- ECR, SSM Parameter Store (free), CloudWatch logs and Route 53. Two public subnets and no NAT gateway: GitHub and the Anthropic API are reached directly.

Spot can reclaim the task with two minutes' notice. A scan running at that moment is failed by the next task's `recover-interrupted --force` with "please run it again"; nothing else is lost, because the database is on EFS.

### The image

`docker/Dockerfile`, multi-stage, built by GitHub Actions and tagged with the commit SHA:

- Base: `php:8.4.x-fpm-bookworm` (exact patch pinned) with extensions installed by `install-php-extensions`: `pcntl posix redis pdo_mysql intl zip opcache`. nginx and supervisord in the same container (the `serversideup/php:8.4-fpm-nginx` image is an acceptable base if you prefer not to maintain that wiring; it pins the same way).
- Composer: `composer install --no-dev --classmap-authoritative` from `composer.lock` (PHPStan and Pint are pinned there). The detached PHPStan phar copy `storage/sentinel-tools/phpstan.phar` is made at build time. Views are cached at build time; config and routes are cached by the entrypoint at container start, because the config holds the injected secrets and Livewire's endpoint prefix is a hash of `APP_KEY` (a route cache built without the key 404s every Livewire request in production).
- Node 24 (exact version) copied from the official `node:24.x.y-bookworm-slim` image; `npm ci --omit=dev` installs ESLint, `typescript-eslint` and jscpd from `package-lock.json`; `npm run build` produces the Vite assets in a separate stage so devDependencies never reach the runtime image.
- Semgrep, gitleaks and Ruff at exact versions from `docker/tools.env` (`SEMGREP_VERSION`, `GITLEAKS_VERSION`, `RUFF_VERSION`, `NODE_VERSION`), the only place those numbers live. gitleaks and Ruff are GitHub release tarballs verified against sha256 sums checked into `docker/checksums.txt`; Semgrep is `pip install --require-hashes -r docker/semgrep-requirements.txt`. Renovate or Dependabot bumps `tools.env` and the sums in one pull request.
- The image sets `SENTINEL_SEMGREP_VERSION`, `SENTINEL_GITLEAKS_VERSION` and `SENTINEL_RUFF_VERSION` from the same build args, and the last build step runs `php artisan sentinel:doctor --strict`, which fails the build when any tool is missing or unpinned, when a binary's reported version differs from its pin (PHPStan, Pint, ESLint and jscpd are checked against what `composer.lock` and `package-lock.json` installed), when a required PHP extension (`zlib mbstring pdo_mysql redis pcntl posix`) is not loaded, or when `memory_limit` is below `SENTINEL_WORKER_MEMORY_LIMIT` (1G; the CLI default of 128M killed a scan of laravel/framework). A drifted version is therefore a red build, never a first scan with an unverified tool.
- CI runs the canary tests (`tests/Feature/Scanning/MaliciousConfigsTest.php`, `ProductionWorkspaceTest.php` and the analyser tests) inside the built image, not on the runner, so the flags are proven against the binaries that ship.
- Runs as a non-root user; `SENTINEL_SCAN_STORAGE_PATH=/tmp/sentinel/scans` on the task's ephemeral storage (20 GB comes with every Fargate task; a scan needs at most 150 MB of extracted files plus the compressed archive and tool output, so scans need no EFS and no extra storage; EFS holds only the SQLite database).
- Repositories arrive as one tarball (three GitHub API requests per scan, whatever the file count), streamed to disk with the byte limit applied to the download and extracted entry by entry by our own tar reader: symlinks and hard links are never written, and the file and byte limits abort extraction early. Limits count analysable files only (`SENTINEL_MAX_FILE_COUNT` 12,000, `SENTINEL_MAX_TOTAL_BYTES` 150 MB). An analyser that times out or crashes is recorded on the scan and the pipeline continues without it: the results page and the reviewer are told which tool did not run and what the language was left with, and only fetch, preflight and normalisation failures fail a scan.

### Secrets and configuration

Non-secret configuration is plain environment in the task definition (`infra/envs/prod/main.tf`). Secrets are injected by ECS from SSM Parameter Store SecureStrings under `/sentinel-slop/prod/` at task start (`valueFrom`), never baked into the image: `APP_KEY` (generated by Terraform), `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_PRIVATE_KEY` and `ANTHROPIC_API_KEY` (shells you fill in).

Production supplies the PEM through `GITHUB_APP_PRIVATE_KEY`, base64-encoded on one line (`base64 -w0 github-app.pem`) so it survives any secrets manager and shell; the raw PEM is accepted too, and it takes precedence over `GITHUB_APP_PRIVATE_KEY_PATH`, which stays for local development. The task role allows only the ECS Exec channel: the application uses no AWS API. The execution role gets ECR pull, CloudWatch logs and `ssm:GetParameters` on those five ARNs and nothing else.

Other production settings: `LOG_CHANNEL=stderr` (CloudWatch collects it), `SESSION_DRIVER=database`, `CACHE_STORE=file`, `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=null` (the scan page polls), `SENTINEL_JOB_TIMEOUT=2700` (one pipeline stage; `DB_QUEUE_RETRY_AFTER` defaults to it plus 60 s and must stay above it, see `config/queue.php`). Horizon and Reverb are installed but not run in production.

### Deploys

`.github/workflows/deploy.yml` has three jobs. `test` builds the Dockerfile's `test` target on every push and pull request: the production image plus dev dependencies, running Pint, PHPStan and the whole Pest suite against the analyser binaries that ship; it needs no AWS access. `push` (main only) depends on `test`, builds the `runtime` target from the same layer cache and pushes it to ECR tagged with the commit SHA, so nothing that failed a test reaches ECR. `deploy` depends on `push`: it registers a task-definition revision pointing at the new image and updates the service. The service deploys with minimum healthy 0% and maximum 100%, so the old task stops before the new one starts (SQLite has one writer, and the new task's forced recovery would fail a scan the old one was still running). The new task migrates before it serves anything; a failed migration stops it and the circuit breaker rolls back. The workflow assumes the Terraform deploy role through OIDC (main branch only) and reads one repository variable, `AWS_DEPLOY_ROLE_ARN`, from `terraform output deploy_role_arn`.

### Terraform layout

```
infra/
  envs/
    prod/            versions.tf (providers incl. us-east-1 for the certificate, S3 backend), main.tf wiring the modules,
                     variables.tf, outputs.tf, backend.hcl.example and terraform.tfvars.example (copy both, both gitignored)
  modules/
    network/         VPC, two public subnets, no NAT; security groups for the task (CloudFront only) and EFS (task only)
    ecr/             repository (immutable tags, scan on push), lifecycle policy keeping the last 10 images
    efs/             encrypted file system for SQLite (backups off), access point (uid/gid 33), a mount target per subnet
    cdn/             us-east-1 certificate, CloudFront distribution, the site A/AAAA aliases and the origin record
    origin-dns/      EventBridge rule on task RUNNING + the Lambda that points origin.<domain> at the task's IP
    secrets/         one SSM SecureString per value under /sentinel-slop/prod/; APP_KEY generated,
                     the GitHub App and Anthropic values are shells you fill before the first deploy
    ecs-cluster/     cluster (Fargate + Fargate Spot) and one CloudWatch log group, 30-day retention
    iam/             the execution role scoped to the secret ARNs, a task role that may only open the ECS Exec
                     channel, the GitHub Actions OIDC deploy role (main branch of this repository only)
    ecs-service/     the task definition (EFS volume, CONTAINER_ROLE=all) and the Spot service; task_definition is
                     ignored after creation (the pipeline registers revisions)
```

Bring it up in this order:

1. Create the state bucket once (commands in `backend.hcl.example`), copy the two example files and fill them in.
2. Push to `main` so the pipeline builds and pushes an image tagged with the commit SHA (its deploy job fails the first time, because the service does not exist yet).
3. `terraform init -backend-config=backend.hcl`, then `terraform apply -var image_tag=<that SHA>`. CloudFront and the certificate take ten to twenty minutes.
4. Write the four external secrets with `aws ssm put-parameter --overwrite --type SecureString --name /sentinel-slop/prod/<NAME> --value ...` (`terraform output secret_arns` lists them; in Git Bash set `MSYS_NO_PATHCONV=1` or the leading slash becomes a Windows path): the production GitHub App's client secret, webhook secret and private key (`base64 -w0 app.pem`), and the Anthropic key. Then `aws ecs update-service --cluster sentinel-slop-prod --service sentinel-slop-prod-app --force-new-deployment`.
5. Point the production GitHub App at `https://<domain>` (see "Production GitHub App").

Set `create_oidc_provider = false` on the iam module if the account already has the GitHub OIDC provider from the other application. The deploy role trusts one exact OIDC subject, `repo:<owner>@<owner id>/<name>@<repository id>:ref:refs/heads/main`: GitHub embeds the numeric ids (`gh api repos/<owner>/<name> --jq '.id, .owner.id'`), IAM can evaluate only `sub` and `aud` from the token, and a plain `repo:<owner>/<name>:...` policy fails with "Not authorized to perform sts:AssumeRoleWithWebIdentity". The ids are the `github_owner_id` and `github_repository_id` variables.

### Reaching the database

ECS Exec is enabled, so the running task is the way in (the Session Manager plugin must be installed once: `winget install Amazon.SessionManagerPlugin`):

```powershell
$env:AWS_PROFILE = "personal"
$task = aws ecs list-tasks --cluster sentinel-slop-prod --service-name sentinel-slop-prod-app --query 'taskArns[0]' --output text
aws ecs execute-command --cluster sentinel-slop-prod --task $task --container all --interactive --command "gosu www-data php artisan tinker"
```

The database is the file `/mnt/data/database.sqlite` inside the task. A task started before ECS Exec was enabled has no agent ("TargetNotConnected"): `--force-new-deployment` replaces it.

### Production GitHub App

A GitHub App has one webhook URL and one setup URL, so the development app (webhook pointed at `herd share`) cannot also serve production. Create a second App for production with Homepage `https://<domain>`, Callback `https://<domain>/auth/github/callback`, Setup `https://<domain>/github/setup`, Webhook `https://<domain>/webhooks/github`, the same permissions (Contents and Metadata read-only) and events (Installation, Installation repositories). It has its own App ID, client ID and secret, private key and webhook secret; installations on the development App do not carry over.

### Monthly cost (London, before LLM usage)

| Item | Approx. USD / month |
|---|---|
| Fargate Spot, 0.5 vCPU / 2 GB, always on | 7.50 |
| Public IPv4 address on the task | 3.65 |
| Route 53 hosted zone | 0.50 |
| EFS, EFS backups, ECR, CloudWatch logs, Lambda, CloudFront (free tier at this traffic) | under 1 |
| **Total** | **about 12** (Dog Desk is about 11: the same shape at 0.5 vCPU / 1 GB) |

From 25 September to 1 October 2026 this ran as three on-demand services (web, a 2 vCPU / 4 GB worker and a scheduler) behind an ALB with RDS MySQL and ElastiCache, at about $6.40 a day, which is about $190 a month. That shape is for traffic this site does not have. Add capacity only when a measured scan or page needs it: `app_cpu` / `app_memory` first, on-demand capacity next, a separate worker service last. LLM spend is per scan and separate (see the cost figures in the scan reports).
