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

## Deployment (AWS, ECS Fargate, Terraform)

Production is AWS, provisioned with Terraform, deployed by GitHub Actions. Nothing here is built yet; this section is the plan the infrastructure is provisioned from. Forge is not used.

### Shape

One container image, three ECS services on one Fargate cluster, all from the same image with different commands:

| Service | Command | Size | Exposed | Needs |
|---|---|---|---|---|
| `web` | php-fpm + nginx (image default) | 0.5 vCPU / 1 GB | ALB target group, port 8080, health check `/up` | DB, Redis, GitHub OAuth + webhook secrets, Reverb app key |
| `worker` | `php artisan horizon` plus a `schedule:work` sidecar container | 1 vCPU / 2 GB (2 / 4 when PHPStan on large repositories is too slow) | nothing | DB, Redis, GitHub App private key, Anthropic key, Reverb app secret |
| `reverb` (optional, see below) | `php artisan reverb:start --host=0.0.0.0 --port=8080` | 0.25 vCPU / 0.5 GB | ALB rule for `/app/*` and `/apps/*`, WebSocket | Redis, Reverb app credentials |

Around them: RDS MySQL 8 (`db.t4g.micro`, 20 GB gp3, single AZ, automated backups), ElastiCache Valkey (`cache.t4g.micro`, one node), ECR, an ALB with an ACM certificate, Secrets Manager (or SSM Parameter Store SecureString; pick whichever the other application already uses), CloudWatch log groups per service, Route 53. Tasks run in public subnets with public IPs and a security group that only accepts the ALB, so there is no NAT gateway to pay for: GitHub and the Anthropic API are reached directly.

### The image

`docker/Dockerfile`, multi-stage, built by GitHub Actions and tagged with the commit SHA:

- Base: `php:8.4.x-fpm-bookworm` (exact patch pinned) with extensions installed by `install-php-extensions`: `pcntl posix redis pdo_mysql intl zip opcache`. nginx and supervisord in the same container (the `serversideup/php:8.4-fpm-nginx` image is an acceptable base if you prefer not to maintain that wiring; it pins the same way).
- Composer: `composer install --no-dev --classmap-authoritative` from `composer.lock` (PHPStan and Pint are pinned there). The detached PHPStan phar copy `storage/sentinel-tools/phpstan.phar` is made at build time so the runtime filesystem can be read-only apart from `/tmp` and the scan workspace.
- Node 24 (exact version) copied from the official `node:24.x.y-bookworm-slim` image; `npm ci --omit=dev` installs ESLint, `typescript-eslint` and jscpd from `package-lock.json`; `npm run build` produces the Vite assets in a separate stage so devDependencies never reach the runtime image.
- Semgrep, gitleaks and Ruff at exact versions from `docker/tools.env` (`SEMGREP_VERSION`, `GITLEAKS_VERSION`, `RUFF_VERSION`, `NODE_VERSION`), the only place those numbers live. gitleaks and Ruff are GitHub release tarballs verified against sha256 sums checked into `docker/checksums.txt`; Semgrep is `pip install --require-hashes -r docker/semgrep-requirements.txt`. Renovate or Dependabot bumps `tools.env` and the sums in one pull request.
- The image sets `SENTINEL_SEMGREP_VERSION`, `SENTINEL_GITLEAKS_VERSION` and `SENTINEL_RUFF_VERSION` from the same build args, and the last build step runs `php artisan sentinel:doctor --strict`, which fails the build when any binary's reported version differs from its pin. A drifted version is therefore a red build, never a first scan with an unverified tool.
- CI runs the canary tests (`tests/Feature/Scanning/MaliciousConfigsTest.php`, `ProductionWorkspaceTest.php` and the analyser tests) inside the built image, not on the runner, so the flags are proven against the binaries that ship.
- Runs as a non-root user; `SENTINEL_SCAN_STORAGE_PATH=/tmp/sentinel/scans` on the task's ephemeral storage (20 GB comes with every Fargate task; a scan needs at most 50 MB of files plus tool output, so no EFS and no extra storage).

### Secrets and configuration

Non-secret configuration is plain environment in the task definition. Secrets are injected by ECS from Secrets Manager or SSM at task start (`valueFrom`), never baked into the image, and each service's execution role may read only its own list:

| Value | web | worker | reverb |
|---|---|---|---|
| `APP_KEY` | yes | yes | yes |
| `DB_PASSWORD` | yes | yes | no |
| `REDIS_PASSWORD` | yes | yes | yes |
| `GITHUB_APP_CLIENT_SECRET` (login) | yes | no | no |
| `GITHUB_APP_WEBHOOK_SECRET` | yes | no | no |
| `GITHUB_APP_PRIVATE_KEY` (PEM contents; mints installation tokens) | no | yes | no |
| `ANTHROPIC_API_KEY` | no | yes | no |
| `REVERB_APP_SECRET` | yes (client auth) | yes (publishes events) | yes |

`GITHUB_APP_PRIVATE_KEY_PATH` becomes optional: production supplies the PEM through `GITHUB_APP_PRIVATE_KEY` (multi-line values are fine in Secrets Manager). Task roles (what the code can call on AWS at runtime) are empty for every service: the application uses no AWS API. Execution roles get ECR pull, CloudWatch logs and `GetSecretValue`/`GetParameters` on the ARNs in the table above and nothing else.

Other production settings: `LOG_CHANNEL=stderr` (CloudWatch collects it), `SESSION_DRIVER=database`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_QUEUE_RETRY_AFTER=960` (must exceed the 900 s stage timeout, see `config/queue.php`), `SENTINEL_SCAN_WORKERS=1` per worker task (scale by adding tasks, not processes, so one scan cannot starve another of the task's CPU), trusted proxies set to the VPC CIDR so the ALB's `X-Forwarded-Proto` produces https URLs.

### Reverb or polling

The scan page polls every 5 seconds whenever websockets are unavailable, so Reverb is an optimisation. Running it on ECS needs its own service, an ALB listener rule for `/app/*` (the WebSocket) and `/apps/*` (the publish API the web and worker tasks call), an ALB idle timeout above Reverb's ping interval (set 120 s), a target-group health check that accepts `200-499` on `/`, and `REVERB_SCALING_ENABLED=true` the moment there are two Reverb tasks. Start with `BROADCAST_CONNECTION=null` and polling; add the Reverb service when the five-second delay on progress updates matters.

### Deploys

`.github/workflows/deploy.yml` on push to `main`: build the image, push to ECR tagged with the SHA (GitHub's OIDC provider assumes a deploy role: ECR push, `ecs:RegisterTaskDefinition`, `ecs:UpdateService`, `ecs:RunTask`, `iam:PassRole` on the two task roles, nothing else), run `php artisan migrate --force` as a one-off task from the new task definition and wait for it, then update `web`, `worker` and `reverb` and wait for `services-stable`.

A scan that is mid-pipeline when the worker is replaced: ECS sends SIGTERM, Horizon stops taking jobs and finishes the current stage, and Fargate sends SIGKILL after `stopTimeout` (120 s at most). Most stages finish inside that; PHPStan on a large repository does not. The workspace is on the old task's disk, so the scan cannot resume on the new task either way. The worker's entrypoint therefore runs `php artisan sentinel:recover-interrupted` before Horizon starts: every scan still in a running status whose workspace does not exist on this task is marked failed with "interrupted by a deployment, please run it again" (or re-dispatched from the start, which is the same cost as the user pressing the button). No manual draining is needed; deploy when Horizon shows the scans queue empty if you would rather nobody notices.

### Terraform layout

```
infra/
  envs/
    prod/            backend.tf (S3 state, DynamoDB lock), main.tf wiring the modules, terraform.tfvars
  modules/
    network/         VPC, two public subnets, security groups (alb, tasks, rds, redis)
    ecr/             repository, lifecycle policy keeping the last 10 images
    rds/             MySQL 8 db.t4g.micro, subnet group, parameter group, password in Secrets Manager
    redis/           ElastiCache Valkey cache.t4g.micro, auth token in Secrets Manager
    secrets/         the secret shells (values written out of band, never in state)
    alb/             ALB, ACM certificate, 443 listener, target groups for web (and reverb), 80 to 443 redirect
    ecs-cluster/     cluster, CloudWatch log groups
    ecs-service/     generic task definition + service; instantiated three times (web, worker, reverb) with command, size, secrets list and target group as inputs
    iam/             execution role per service scoped to its secret ARNs, empty task roles, the GitHub OIDC deploy role
    dns/             Route 53 records for the ALB
```

Mirror the other application's module conventions where they differ; the split that matters is `ecs-service` being generic and `iam` producing one execution role per service.

### Production GitHub App

A GitHub App has one webhook URL and one setup URL, so the development app (webhook pointed at `herd share`) cannot also serve production. Create a second App for production with Homepage `https://<domain>`, Callback `https://<domain>/auth/github/callback`, Setup `https://<domain>/github/setup`, Webhook `https://<domain>/webhooks/github`, the same permissions (Contents and Metadata read-only) and events (Installation, Installation repositories). It has its own App ID, client ID and secret, private key and webhook secret; installations on the development App do not carry over.

### Rough monthly cost at low usage (London, on-demand, before LLM usage)

| Item | Approx. USD / month |
|---|---|
| `web` 0.5 vCPU / 1 GB | 22 |
| `worker` 1 vCPU / 2 GB | 43 |
| ALB + LCUs | 22 |
| RDS db.t4g.micro + 20 GB | 16 |
| ElastiCache cache.t4g.micro | 13 |
| ECR, CloudWatch logs, Secrets Manager, Route 53 | 8 |
| **Total** | **about 125 (about £95)** |

Graviton (arm64) task definitions take roughly 20% off the Fargate lines; a NAT gateway would add about 35; the Reverb service about 11. LLM spend is per scan and separate (see the cost figures in the scan reports).
