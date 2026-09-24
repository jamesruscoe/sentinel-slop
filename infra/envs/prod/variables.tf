variable "region" {
  description = "AWS region. London keeps the data in the UK and is where the cost estimates were made."
  type        = string
  default     = "eu-west-2"
}

variable "domain_name" {
  description = "The site's hostname, e.g. sentinel.example.com. A Route 53 hosted zone for its parent must already exist."
  type        = string
}

variable "hosted_zone_name" {
  description = "The existing Route 53 hosted zone the domain lives in, e.g. example.com."
  type        = string
}

variable "github_repository" {
  description = "owner/name of the GitHub repository whose Actions may deploy (OIDC trust)."
  type        = string
  default     = "jamesruscoe/sentinel-slop"
}

variable "github_owner_id" {
  description = "Numeric id of the repository owner; GitHub embeds it in the OIDC subject the deploy role trusts."
  type        = string
  default     = "131148285"
}

variable "github_repository_id" {
  description = "Numeric id of the repository; embedded in the OIDC subject alongside the owner id."
  type        = string
  default     = "1382103864"
}

variable "image_tag" {
  description = "Image tag the task definitions start on. The deploy pipeline registers new revisions with the commit SHA; Terraform ignores those (lifecycle)."
  type        = string
  default     = "bootstrap"
}

variable "vpc_cidr" {
  type    = string
  default = "10.42.0.0/16"
}

variable "architecture" {
  description = "X86_64 or ARM64 (Graviton, ~20% cheaper). The image must be built for it (docker buildx --platform)."
  type        = string
  default     = "X86_64"
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "redis_node_type" {
  type    = string
  default = "cache.t4g.micro"
}

variable "redis_engine_version" {
  description = "Redis OSS major.minor for a single-node ElastiCache cluster (Valkey needs a replication group)."
  type        = string
  default     = "7.1"
}

variable "web_cpu" {
  type    = number
  default = 512
}

variable "web_memory" {
  type    = number
  default = 1024
}

variable "worker_cpu" {
  description = "2 vCPU: PHPStan and Pint each take about 65 ms per PHP file on one core; a 12,000-file repository needs the headroom."
  type        = number
  default     = 2048
}

variable "worker_memory" {
  type    = number
  default = 4096
}

variable "scheduler_cpu" {
  type    = number
  default = 256
}

variable "scheduler_memory" {
  type    = number
  default = 512
}

variable "github_app_id" {
  description = "Non-secret GitHub App settings for the production App (see README: a second App is needed)."
  type        = string
}

variable "github_app_slug" {
  type = string
}

variable "github_app_client_id" {
  type = string
}

variable "admin_github_usernames" {
  description = "Comma-separated GitHub usernames allowed to open /horizon."
  type        = string
  default     = ""
}

variable "llm_model" {
  type    = string
  default = "claude-sonnet-5"
}

variable "scans_per_user_per_hour" {
  type    = number
  default = 5
}

variable "synthesis_daily_cap" {
  description = "Reviews (LLM calls) per UTC day across all users; the bill's ceiling at about £0.45 each. 0 disables the cap."
  type        = number
  default     = 60
}
