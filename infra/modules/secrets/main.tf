# One Secrets Manager secret per value under <prefix>/<NAME>, so each service's execution role can be
# granted exactly the ARNs it needs. Generated secrets get a value here (APP_KEY as Laravel expects it,
# a database password); external ones are shells whose value you write before the first deploy:
#   aws secretsmanager put-secret-value --secret-id sentinel-slop/prod/ANTHROPIC_API_KEY --secret-string '...'
#   aws secretsmanager put-secret-value --secret-id sentinel-slop/prod/GITHUB_APP_PRIVATE_KEY --secret-string "$(base64 -w0 app.pem)"

resource "random_bytes" "app_key" {
  length = 32
}

resource "random_password" "db_password" {
  length  = 32
  special = false
}

locals {
  generated_values = {
    APP_KEY     = "base64:${random_bytes.app_key.base64}"
    DB_PASSWORD = random_password.db_password.result
  }
}

resource "aws_secretsmanager_secret" "generated" {
  for_each = toset(var.generated)

  name                    = "${var.prefix}/${each.key}"
  recovery_window_in_days = 7
}

resource "aws_secretsmanager_secret_version" "generated" {
  for_each = toset(var.generated)

  secret_id     = aws_secretsmanager_secret.generated[each.key].id
  secret_string = local.generated_values[each.key]
}

resource "aws_secretsmanager_secret" "external" {
  for_each = toset(var.external)

  name                    = "${var.prefix}/${each.key}"
  recovery_window_in_days = 7
}

variable "prefix" {
  type = string
}

variable "generated" {
  description = "Names Terraform generates values for (APP_KEY, DB_PASSWORD)."
  type        = list(string)
}

variable "external" {
  description = "Names whose values are written out of band."
  type        = list(string)
}

output "arns" {
  value = merge(
    { for k, s in aws_secretsmanager_secret.generated : k => s.arn },
    { for k, s in aws_secretsmanager_secret.external : k => s.arn },
  )
}

output "generated_values" {
  value     = local.generated_values
  sensitive = true
}
