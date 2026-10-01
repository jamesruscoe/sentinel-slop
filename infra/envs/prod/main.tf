# Sentinel Slop on the Dog Desk pattern: one Fargate Spot task running everything, SQLite on EFS, CloudFront in
# front and a Lambda that points the origin record at the task. No load balancer, RDS or ElastiCache: those were
# about $150 of a $180 month at the traffic this site has. README "Deployment" has the cost table.

locals {
  name    = "sentinel-slop-prod"
  app_url = "https://${var.domain_name}"
  # The EFS access point is mounted here; the SQLite database is the one file that must survive a task.
  data_path = "/mnt/data"
  port      = 8080
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

module "efs" {
  source = "../../modules/efs"

  name              = local.name
  subnet_ids        = module.network.public_subnet_ids
  security_group_id = module.network.efs_security_group_id
}

module "cdn" {
  source = "../../modules/cdn"

  providers = {
    aws           = aws
    aws.us_east_1 = aws.us_east_1
  }

  name             = local.name
  domain_name      = var.domain_name
  hosted_zone_name = var.hosted_zone_name
  origin_port      = local.port
}

# The A record used to alias the load balancer; it now aliases CloudFront (in-place update, no gap).
moved {
  from = aws_route53_record.site
  to   = module.cdn.aws_route53_record.site["A"]
}

# Every secret is an SSM SecureString under /sentinel-slop/prod/<NAME> (free, as Dog Desk does it). APP_KEY is generated here; the external
# ones (GitHub App, Anthropic) are shells you fill in before the first deploy (README).
module "secrets" {
  source = "../../modules/secrets"

  prefix    = "/sentinel-slop/prod"
  generated = ["APP_KEY"]
  external  = ["GITHUB_APP_CLIENT_SECRET", "GITHUB_APP_WEBHOOK_SECRET", "GITHUB_APP_PRIVATE_KEY", "ANTHROPIC_API_KEY"]
}

module "cluster" {
  source = "../../modules/ecs-cluster"

  name     = local.name
  services = ["app"]
}

module "origin_dns" {
  source = "../../modules/origin-dns"

  name           = local.name
  cluster_arn    = module.cluster.cluster_arn
  hosted_zone_id = module.cdn.hosted_zone_id
  record_name    = module.cdn.origin_fqdn
}

module "iam" {
  source = "../../modules/iam"

  name               = local.name
  ecr_repository_arn = module.ecr.repository_arn
  log_group_arns     = module.cluster.log_group_arns
  cluster_arn        = module.cluster.cluster_arn
  service_secret_arns = {
    app = values(module.secrets.arns)
  }
  github_repository    = var.github_repository
  github_owner_id      = var.github_owner_id
  github_repository_id = var.github_repository_id
}

# Non-secret environment. Secrets are injected by ECS from the ARNs above.
locals {
  environment = {
    APP_NAME    = "Sentinel Slop"
    APP_ENV     = "production"
    APP_DEBUG   = "false"
    APP_URL     = local.app_url
    LOG_CHANNEL = "stderr"
    LOG_LEVEL   = "info"
    # Only CloudFront can reach the task (security group), so its X-Forwarded-For is trusted.
    TRUSTED_PROXIES                  = "*"
    DB_CONNECTION                    = "sqlite"
    DB_DATABASE                      = "${local.data_path}/database.sqlite"
    DB_BUSY_TIMEOUT                  = "15000"
    CACHE_STORE                      = "file"
    QUEUE_CONNECTION                 = "database"
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
    SENTINEL_SOLE_WORKER             = "true"
    SENTINEL_SCAN_STORAGE_PATH       = "/tmp/sentinel/scans"
  }
}

module "app" {
  source = "../../modules/ecs-service"

  name                = "${local.name}-app"
  role                = "all"
  cluster_id          = module.cluster.cluster_id
  image               = "${module.ecr.repository_url}:${var.image_tag}"
  cpu                 = var.app_cpu
  memory              = var.app_memory
  architecture        = var.architecture
  desired_count       = 1
  subnet_ids          = module.network.public_subnet_ids
  security_group_ids  = [module.network.tasks_security_group_id]
  execution_role_arn  = module.iam.execution_role_arns["app"]
  task_role_arn       = module.iam.task_role_arn
  log_group_name      = module.cluster.log_group_names["app"]
  region              = var.region
  environment         = local.environment
  secrets             = module.secrets.arns
  container_port      = local.port
  efs_file_system_id  = module.efs.file_system_id
  efs_access_point_id = module.efs.access_point_id
  efs_mount_path      = local.data_path

  # The Lambda must exist before the first task reaches RUNNING, or the origin record keeps its placeholder.
  depends_on = [module.efs, module.origin_dns]
}
