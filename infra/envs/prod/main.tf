locals {
  name     = "sentinel-slop-prod"
  app_url  = "https://${var.domain_name}"
  db_name  = "sentinel_slop"
  db_user  = "sentinel"
  services = ["web", "worker", "scheduler"]
}

module "network" {
  source = "../../modules/network"

  name = local.name
  cidr = var.vpc_cidr
}

module "ecr" {
  source = "../../modules/ecr"

  name = "sentinel-slop"
}

module "acm" {
  source = "../../modules/acm"

  domain_name      = var.domain_name
  hosted_zone_name = var.hosted_zone_name
}

module "alb" {
  source = "../../modules/alb"

  name              = local.name
  vpc_id            = module.network.vpc_id
  subnet_ids        = module.network.public_subnet_ids
  security_group_id = module.network.alb_security_group_id
  certificate_arn   = module.acm.certificate_arn
}

# Every secret lives in Secrets Manager under sentinel-slop/prod/<NAME>. Generated ones get a value here;
# the external ones (GitHub App, Anthropic) are shells you fill in before the first deploy (README).
module "secrets" {
  source = "../../modules/secrets"

  prefix    = "sentinel-slop/prod"
  generated = ["APP_KEY", "DB_PASSWORD"]
  external  = ["GITHUB_APP_CLIENT_SECRET", "GITHUB_APP_WEBHOOK_SECRET", "GITHUB_APP_PRIVATE_KEY", "ANTHROPIC_API_KEY"]
}

module "rds" {
  source = "../../modules/rds"

  name              = local.name
  subnet_ids        = module.network.private_subnet_ids
  security_group_id = module.network.rds_security_group_id
  instance_class    = var.db_instance_class
  db_name           = local.db_name
  username          = local.db_user
  password          = module.secrets.generated_values["DB_PASSWORD"]
}

module "redis" {
  source = "../../modules/redis"

  name              = local.name
  subnet_ids        = module.network.private_subnet_ids
  security_group_id = module.network.redis_security_group_id
  node_type         = var.redis_node_type
  engine_version    = var.redis_engine_version
}

module "cluster" {
  source = "../../modules/ecs-cluster"

  name     = local.name
  services = local.services
}

# Which secrets each service may read. The worker mints installation tokens and calls the LLM; the web tier
# handles login and webhooks; the scheduler only needs the database. Nothing else is granted.
locals {
  service_secrets = {
    web       = ["APP_KEY", "DB_PASSWORD", "GITHUB_APP_CLIENT_SECRET", "GITHUB_APP_WEBHOOK_SECRET"]
    worker    = ["APP_KEY", "DB_PASSWORD", "GITHUB_APP_PRIVATE_KEY", "ANTHROPIC_API_KEY"]
    scheduler = ["APP_KEY", "DB_PASSWORD"]
  }
}

module "iam" {
  source = "../../modules/iam"

  name               = local.name
  ecr_repository_arn = module.ecr.repository_arn
  log_group_arns     = module.cluster.log_group_arns
  cluster_arn        = module.cluster.cluster_arn
  service_secret_arns = {
    for service, names in local.service_secrets : service => [for n in names : module.secrets.arns[n]]
  }
  github_repository = var.github_repository
}

# Non-secret environment shared by every role. Secrets are injected by ECS from the ARNs above.
locals {
  common_environment = {
    APP_NAME                         = "Sentinel Slop"
    APP_ENV                          = "production"
    APP_DEBUG                        = "false"
    APP_URL                          = local.app_url
    LOG_CHANNEL                      = "stderr"
    LOG_LEVEL                        = "info"
    TRUSTED_PROXIES                  = var.vpc_cidr
    DB_CONNECTION                    = "mysql"
    DB_HOST                          = module.rds.address
    DB_PORT                          = "3306"
    DB_DATABASE                      = local.db_name
    DB_USERNAME                      = local.db_user
    REDIS_CLIENT                     = "phpredis"
    REDIS_HOST                       = module.redis.address
    REDIS_PORT                       = "6379"
    CACHE_STORE                      = "redis"
    QUEUE_CONNECTION                 = "redis"
    SESSION_DRIVER                   = "database"
    SESSION_SECURE_COOKIE            = "true"
    BROADCAST_CONNECTION             = "null"
    FILESYSTEM_DISK                  = "local"
    GITHUB_APP_ID                    = var.github_app_id
    GITHUB_APP_SLUG                  = var.github_app_slug
    GITHUB_APP_CLIENT_ID             = var.github_app_client_id
    GITHUB_APP_REDIRECT_URI          = "${local.app_url}/auth/github/callback"
    SENTINEL_LLM_PROVIDER            = "anthropic"
    SENTINEL_LLM_MODEL               = var.llm_model
    SENTINEL_ADMIN_GITHUB_USERNAMES  = var.admin_github_usernames
    SENTINEL_SCANS_PER_USER_PER_HOUR = tostring(var.scans_per_user_per_hour)
    SENTINEL_SYNTHESIS_DAILY_CAP     = tostring(var.synthesis_daily_cap)
    SENTINEL_SCAN_WORKERS            = "1"
    SENTINEL_SOLE_WORKER             = "true"
    SENTINEL_SCAN_STORAGE_PATH       = "/tmp/sentinel/scans"
  }
  image = "${module.ecr.repository_url}:${var.image_tag}"
}

module "web" {
  source = "../../modules/ecs-service"

  name               = "${local.name}-web"
  role               = "web"
  cluster_id         = module.cluster.cluster_id
  image              = local.image
  cpu                = var.web_cpu
  memory             = var.web_memory
  architecture       = var.architecture
  desired_count      = 1
  subnet_ids         = module.network.public_subnet_ids
  security_group_ids = [module.network.tasks_security_group_id]
  execution_role_arn = module.iam.execution_role_arns["web"]
  task_role_arn      = module.iam.task_role_arn
  log_group_name     = module.cluster.log_group_names["web"]
  region             = var.region
  environment        = local.common_environment
  secrets            = { for n in local.service_secrets.web : n => module.secrets.arns[n] }
  container_port     = 8080
  target_group_arn   = module.alb.target_group_arn
  depends_on         = [module.alb]
}

module "worker" {
  source = "../../modules/ecs-service"

  name               = "${local.name}-worker"
  role               = "worker"
  cluster_id         = module.cluster.cluster_id
  image              = local.image
  cpu                = var.worker_cpu
  memory             = var.worker_memory
  architecture       = var.architecture
  desired_count      = 1
  subnet_ids         = module.network.public_subnet_ids
  security_group_ids = [module.network.tasks_security_group_id]
  execution_role_arn = module.iam.execution_role_arns["worker"]
  task_role_arn      = module.iam.task_role_arn
  log_group_name     = module.cluster.log_group_names["worker"]
  region             = var.region
  environment        = local.common_environment
  secrets            = { for n in local.service_secrets.worker : n => module.secrets.arns[n] }
  # Fargate's maximum. Horizon finishes the current stage inside it when it can; otherwise the scan is
  # failed by sentinel:recover-interrupted at the next worker start.
  stop_timeout = 120
}

module "scheduler" {
  source = "../../modules/ecs-service"

  name               = "${local.name}-scheduler"
  role               = "scheduler"
  cluster_id         = module.cluster.cluster_id
  image              = local.image
  cpu                = var.scheduler_cpu
  memory             = var.scheduler_memory
  architecture       = var.architecture
  desired_count      = 1
  subnet_ids         = module.network.public_subnet_ids
  security_group_ids = [module.network.tasks_security_group_id]
  execution_role_arn = module.iam.execution_role_arns["scheduler"]
  task_role_arn      = module.iam.task_role_arn
  log_group_name     = module.cluster.log_group_names["scheduler"]
  region             = var.region
  environment        = local.common_environment
  secrets            = { for n in local.service_secrets.scheduler : n => module.secrets.arns[n] }
}

data "aws_route53_zone" "this" {
  name = var.hosted_zone_name
}

resource "aws_route53_record" "site" {
  zone_id = data.aws_route53_zone.this.zone_id
  name    = var.domain_name
  type    = "A"

  alias {
    name                   = module.alb.dns_name
    zone_id                = module.alb.zone_id
    evaluate_target_health = true
  }
}
