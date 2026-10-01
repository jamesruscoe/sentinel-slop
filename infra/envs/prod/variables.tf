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
  description = "Image tag the task definition starts on. The deploy pipeline registers new revisions with the commit SHA; Terraform ignores those (lifecycle)."
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

variable "app_cpu" {
  description = "0.5 vCPU on Fargate Spot (~$5/month), Dog Desk's size. PHPStan and Pint take about 65 ms per PHP file each on a full core, so the 900 s tool timeout covers roughly 3,000 PHP files at half a core; a larger repository loses the tool that timed out, not the scan."
  type        = number
  default     = 512
}

variable "app_memory" {
  description = "2 GB (~$2.30/month on Spot), one more than Dog Desk: php.ini allows a scan 1 GB and Semgrep runs alongside nginx, php-fpm and the workers, so 1 GB would be OOM-killed mid-scan."
  type        = number
  default     = 2048
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
