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
| `worker` | entrypoint runs `php artisan sentinel:recover-interrupted --force`, then `php artisan horizon`; a `schedule:work` sidecar container | 2 vCPU / 4 GB (PHPStan and Pint each take ~65 ms per PHP file on a laptop core; `SENTINEL_TOOL_TIMEOUT` is 900 s and `SENTINEL_JOB_TIMEOUT` 2,700 s) | nothing | DB, Redis, GitHub App private key, Anthropic key, Reverb app secret |
| `reverb` (optional, see below) | `php artisan reverb:start --host=0.0.0.0 --port=8080` | 0.25 vCPU / 0.5 GB | ALB rule for `/app/*` and `/apps/*`, WebSocket | Redis, Reverb app credentials |

Around them: RDS MySQL 8 (`db.t4g.micro`, 20 GB gp3, single AZ, automated backups), ElastiCache Valkey (`cache.t4g.micro`, one node), ECR, an ALB with an ACM certificate, Secrets Manager (or SSM Parameter Store SecureString; pick whichever the other application already uses), CloudWatch log groups per service, Route 53. Tasks run in public subnets with public IPs and a security group that only accepts the ALB, so there is no NAT gateway to pay for: GitHub and the Anthropic API are reached directly.

### The image

`docker/Dockerfile`, multi-stage, built by GitHub Actions and tagged with the commit SHA:

- Base: `php:8.4.x-fpm-bookworm` (exact patch pinned) with extensions installed by `install-php-extensions`: `pcntl posix redis pdo_mysql intl zip opcache`. nginx and supervisord in the same container (the `serversideup/php:8.4-fpm-nginx` image is an acceptable base if you prefer not to maintain that wiring; it pins the same way).
- Composer: `composer install --no-dev --classmap-authoritative` from `composer.lock` (PHPStan and Pint are pinned there). The detached PHPStan phar copy `storage/sentinel-tools/phpstan.phar` is made at build time. Views are cached at build time; config and routes are cached by the entrypoint at container start, because the config holds the injected secrets and Livewire's endpoint prefix is a hash of `APP_KEY` (a route cache built without the key 404s every Livewire request in production).
- Node 24 (exact version) copied from the official `node:24.x.y-bookworm-slim` image; `npm ci --omit=dev` installs ESLint, `typescript-eslint` and jscpd from `package-lock.json`; `npm run build` produces the Vite assets in a separate stage so devDependencies never reach the runtime image.
- Semgrep, gitleaks and Ruff at exact versions from `docker/tools.env` (`SEMGREP_VERSION`, `GITLEAKS_VERSION`, `RUFF_VERSION`, `NODE_VERSION`), the only place those numbers live. gitleaks and Ruff are GitHub release tarballs verified against sha256 sums checked into `docker/checksums.txt`; Semgrep is `pip install --require-hashes -r docker/semgrep-requirements.txt`. Renovate or Dependabot bumps `tools.env` and the sums in one pull request.
- The image sets `SENTINEL_SEMGREP_VERSION`, `SENTINEL_GITLEAKS_VERSION` and `SENTINEL_RUFF_VERSION` from the same build args, and the last build step runs `php artisan sentinel:doctor --strict`, which fails the build when any tool is missing or unpinned, when a binary's reported version differs from its pin (PHPStan, Pint, ESLint and jscpd are checked against what `composer.lock` and `package-lock.json` installed), when a required PHP extension (`zlib mbstring pdo_mysql redis pcntl posix`) is not loaded, or when `memory_limit` is below `SENTINEL_WORKER_MEMORY_LIMIT` (1G; the CLI default of 128M killed a scan of laravel/framework). A drifted version is therefore a red build, never a first scan with an unverified tool.
- CI runs the canary tests (`tests/Feature/Scanning/MaliciousConfigsTest.php`, `ProductionWorkspaceTest.php` and the analyser tests) inside the built image, not on the runner, so the flags are proven against the binaries that ship.
- Runs as a non-root user; `SENTINEL_SCAN_STORAGE_PATH=/tmp/sentinel/scans` on the task's ephemeral storage (20 GB comes with every Fargate task; a scan needs at most 150 MB of extracted files plus the compressed archive and tool output, so no EFS and no extra storage).
- Repositories arrive as one tarball (three GitHub API requests per scan, whatever the file count), streamed to disk with the byte limit applied to the download and extracted entry by entry by our own tar reader: symlinks and hard links are never written, and the file and byte limits abort extraction early. Limits count analysable files only (`SENTINEL_MAX_FILE_COUNT` 12,000, `SENTINEL_MAX_TOTAL_BYTES` 150 MB). An analyser that times out or crashes is recorded on the scan and the pipeline continues without it: the results page and the reviewer are told which tool did not run and what the language was left with, and only fetch, preflight and normalisation failures fail a scan.

### Secrets and configuration

Non-secret configuration is plain environment in the task definition. Secrets are injected by ECS from Secrets Manager or SSM at task start (`valueFrom`), never baked into the image, and each service's execution role may read only its own list:

| Value | web | worker | reverb |
|---|---|---|---|
| `APP_KEY` | yes | yes | yes |
| `DB_PASSWORD` | yes | yes | no |
| `REDIS_PASSWORD` | yes | yes | yes |
| `GITHUB_APP_CLIENT_SECRET` (login) | yes | no | no |
| `GITHUB_APP_WEBHOOK_SECRET` | yes | no | no |
| `GITHUB_APP_PRIVATE_KEY` (PEM contents; mints installation tokens: the worker for scans, the web tier to claim an installation and sync its repositories on `/github/setup`) | yes | yes | no |
| `ANTHROPIC_API_KEY` | no | yes | no |
| `REVERB_APP_SECRET` | yes (client auth) | yes (publishes events) | yes |

Production supplies the PEM through `GITHUB_APP_PRIVATE_KEY`, base64-encoded on one line (`base64 -w0 github-app.pem`) so it survives any secrets manager and shell; the raw PEM is accepted too, and it takes precedence over `GITHUB_APP_PRIVATE_KEY_PATH`, which stays for local development. Task roles (what the code can call on AWS at runtime) are empty for every service: the application uses no AWS API. Execution roles get ECR pull, CloudWatch logs and `GetSecretValue`/`GetParameters` on the ARNs in the table above and nothing else.

Other production settings: `LOG_CHANNEL=stderr` (CloudWatch collects it), `SESSION_DRIVER=database`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SENTINEL_JOB_TIMEOUT=2700` (one pipeline stage; `REDIS_QUEUE_RETRY_AFTER` defaults to it plus 60 s and must stay above it, see `config/queue.php`), `SENTINEL_SCAN_WORKERS=1` per worker task (scale by adding tasks, not processes, so one scan cannot starve another of the task's CPU), trusted proxies set to the VPC CIDR so the ALB's `X-Forwarded-Proto` produces https URLs.

### Reverb or polling

The scan page polls every 5 seconds whenever websockets are unavailable, so Reverb is an optimisation. Running it on ECS needs its own service, an ALB listener rule for `/app/*` (the WebSocket) and `/apps/*` (the publish API the web and worker tasks call), an ALB idle timeout above Reverb's ping interval (set 120 s), a target-group health check that accepts `200-499` on `/`, and `REVERB_SCALING_ENABLED=true` the moment there are two Reverb tasks. Start with `BROADCAST_CONNECTION=null` and polling; add the Reverb service when the five-second delay on progress updates matters.

### Deploys

`.github/workflows/deploy.yml` has three jobs. `test` builds the Dockerfile's `test` target on every push and pull request: the production image plus dev dependencies, running Pint, PHPStan and the whole Pest suite against the analyser binaries that ship; it needs no AWS access. `push` (main only) depends on `test`, builds the `runtime` target from the same layer cache and pushes it to ECR tagged with the commit SHA, so nothing that failed a test reaches ECR. `deploy` depends on `push`: it registers a task-definition revision per service pointing at the new image, runs `php artisan migrate --force` as a one-off task from the web revision (its execution role holds the database password), waits for it and fails the deploy on a non-zero exit, then updates `web`, `worker` and `scheduler` and waits for `services-stable`. The workflow assumes the Terraform deploy role through OIDC (main branch only) and reads three repository variables from `terraform output`: `AWS_DEPLOY_ROLE_ARN`, `ECS_SUBNET_IDS` (public subnets, comma-separated) and `ECS_TASKS_SECURITY_GROUP`.

The first migration is the first deploy: push to `main` after the variables are set and the pipeline runs it before any service starts on the new image. To run migrations by hand instead, the same one-off task from a shell with the deploy or admin profile:

```
aws ecs run-task --cluster sentinel-slop-prod --launch-type FARGATE --task-definition sentinel-slop-prod-web \
  --network-configuration "awsvpcConfiguration={subnets=[<public subnet ids>],securityGroups=[<tasks sg>],assignPublicIp=ENABLED}" \
  --overrides '{"containerOverrides":[{"name":"web","command":["php","artisan","migrate","--force"],"environment":[{"name":"CONTAINER_ROLE","value":"migrate"}]}]}'
```

A scan that is mid-pipeline when the worker is replaced: ECS sends SIGTERM, Horizon stops taking jobs and finishes the current stage, and Fargate sends SIGKILL after `stopTimeout` (120 s at most). Most stages finish inside that; PHPStan on a large repository does not. The workspace is on the old task's disk, so the scan cannot resume on the new task either way. `php artisan sentinel:recover-interrupted` handles it: every scan still in a running status that nothing has touched for longer than a stage may run (`SENTINEL_JOB_TIMEOUT` plus two minutes; every stage updates the row when it starts) is marked failed with "interrupted by a deployment or worker restart, please run it again", and workspaces of finished or unknown scans are removed. It is scheduled every five minutes, so nothing is stuck for longer than about one stage timeout. With a single worker task, the entrypoint runs it with `--force` before Horizon starts, which fails every running scan immediately; never use `--force` with more than one worker task, since another task's scan would be failed under it. That includes the overlap of a rolling deploy: the worker service therefore deploys with minimum healthy 0% and maximum 100%, stopping the old task before starting the new one (the queue waits for the gap), whereas the web service keeps the usual 100%/200% so the site never drops. No manual draining is needed; deploy when Horizon shows the scans queue empty if you would rather nobody notices.

### Terraform layout

```
infra/
  envs/
    prod/            versions.tf (providers, S3 backend), main.tf wiring the modules, variables.tf, outputs.tf,
                     backend.hcl.example and terraform.tfvars.example (copy both, fill in, both gitignored)
  modules/
    network/         VPC, two public + two private subnets, no NAT; security groups for alb, tasks, rds, redis
    ecr/             repository (immutable tags, scan on push), lifecycle policy keeping the last 10 images
    acm/             certificate for the domain, DNS-validated in the existing hosted zone
    alb/             ALB, 443 listener with the certificate, 80 to 443 redirect, web target group (/up)
    secrets/         one Secrets Manager secret per value under sentinel-slop/prod/; APP_KEY and DB_PASSWORD generated,
                     the GitHub App and Anthropic values are shells you fill before the first deploy
    rds/             MySQL 8.4 db.t4g.micro, 20 GB gp3 autoscaling to 100, encrypted, 7-day backups, deletion protection
    redis/           ElastiCache Redis 7.1 cache.t4g.micro, one node, reachable from the tasks' security group only
                     (Valkey is only offered through replication groups, which a single node does not need)
    ecs-cluster/     cluster (Fargate) and one CloudWatch log group per service, 30-day retention
    iam/             one execution role per service scoped to its secret ARNs, a task role that may only open the
                     ECS Exec channel, the GitHub Actions OIDC deploy role (main branch of this repository only)
    ecs-service/     generic task definition + service, instantiated for web, worker and scheduler with role,
                     size, secrets and target group as inputs; task_definition ignored after creation (the pipeline registers revisions)
```

Bring it up in this order:

1. Create the state bucket and lock table once (commands in `backend.hcl.example`), copy the two example files and fill them in.
2. `terraform init -backend-config=backend.hcl && terraform plan`, then `apply`. RDS and the certificate validation take about ten minutes.
3. Write the four external secrets (`terraform output secret_arns` lists them): the production GitHub App's client secret, webhook secret and private key (`base64 -w0 app.pem`), and the Anthropic key.
4. Build and push the first image with the `bootstrap` tag, or let the pipeline's first run register the SHA-tagged revision; the services start once an image exists at the tag their task definition names.
5. Run the migrations once as a one-off task (the pipeline does this on every deploy): `aws ecs run-task` on the web task definition with `CONTAINER_ROLE=migrate` and command `php artisan migrate --force`.
6. Point the production GitHub App at `https://<domain>` (see "Production GitHub App").

Set `create_oidc_provider = false` on the iam module if the account already has the GitHub OIDC provider from the other application. The deploy role trusts one exact OIDC subject, `repo:<owner>@<owner id>/<name>@<repository id>:ref:refs/heads/main`: GitHub embeds the numeric ids (`gh api repos/<owner>/<name> --jq '.id, .owner.id'`), IAM can evaluate only `sub` and `aud` from the token, and a plain `repo:<owner>/<name>:...` policy fails with "Not authorized to perform sts:AssumeRoleWithWebIdentity". The ids are the `github_owner_id` and `github_repository_id` variables.

### Reaching the database and Redis

Neither has a public endpoint. ECS Exec is enabled on every service, so a running task is the tunnel (the Session Manager plugin must be installed once: `winget install Amazon.SessionManagerPlugin`):

```powershell
$env:AWS_PROFILE = "personal"
$task    = aws ecs list-tasks --cluster sentinel-slop-prod --service-name sentinel-slop-prod-web --query 'taskArns[0]' --output text
$runtime = aws ecs describe-tasks --cluster sentinel-slop-prod --tasks $task --query 'tasks[0].containers[0].runtimeId' --output text
$taskId  = $task.Split('/')[-1]
aws ssm start-session --target "ecs:sentinel-slop-prod_${taskId}_${runtime}" `
  --document-name AWS-StartPortForwardingSessionToRemoteHost `
  --parameters "{\`"host\`":[\`"$(terraform -chdir=infra/envs/prod output -raw db_address)\`"],\`"portNumber\`":[\`"3306\`"],\`"localPortNumber\`":[\`"13306\`"]}"
```

While that session is open, a MySQL client connects to `127.0.0.1:13306` as `sentinel` with the password from `aws secretsmanager get-secret-value --secret-id sentinel-slop/prod/DB_PASSWORD --query SecretString --output text`. The same command with the Redis address and port 6379 reaches ElastiCache. A task started before ECS Exec was enabled has no agent ("TargetNotConnected"): `aws ecs update-service --cluster sentinel-slop-prod --service sentinel-slop-prod-web --force-new-deployment` replaces it. The migration one-off task and the `TRUSTED_PROXIES` variable (the VPC CIDR) are the only things the application needs from the infrastructure beyond its environment.

### Production GitHub App

A GitHub App has one webhook URL and one setup URL, so the development app (webhook pointed at `herd share`) cannot also serve production. Create a second App for production with Homepage `https://<domain>`, Callback `https://<domain>/auth/github/callback`, Setup `https://<domain>/github/setup`, Webhook `https://<domain>/webhooks/github`, the same permissions (Contents and Metadata read-only) and events (Installation, Installation repositories). It has its own App ID, client ID and secret, private key and webhook secret; installations on the development App do not carry over.

### Rough monthly cost at low usage (London, on-demand, before LLM usage)

| Item | Approx. USD / month |
|---|---|
| `web` 0.5 vCPU / 1 GB | 22 |
| `worker` 2 vCPU / 4 GB | 87 |
| ALB + LCUs | 22 |
| RDS db.t4g.micro + 20 GB | 16 |
| ElastiCache cache.t4g.micro | 13 |
| ECR, CloudWatch logs, Secrets Manager, Route 53 | 8 |
| **Total** | **about 170 (about £130); about 125 with a 1 vCPU / 2 GB worker, which is enough below ~5,000 PHP files** |

Graviton (arm64) task definitions take roughly 20% off the Fargate lines; a NAT gateway would add about 35; the Reverb service about 11. LLM spend is per scan and separate (see the cost figures in the scan reports).
