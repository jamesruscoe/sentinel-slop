# One SSM Parameter Store SecureString per value under <prefix>/<NAME>, as Dog Desk keeps its secrets: standard
# parameters cost nothing (Secrets Manager was $0.40 a secret a month). Generated values get a value here (APP_KEY
# as Laravel expects it); external ones are created with a placeholder whose value you write before the first
# deploy, and Terraform never reads it back or overwrites it:
#   aws ssm put-parameter --overwrite --type SecureString --name /sentinel-slop/prod/ANTHROPIC_API_KEY --value '...'
#   aws ssm put-parameter --overwrite --type SecureString --name /sentinel-slop/prod/GITHUB_APP_PRIVATE_KEY --value "$(base64 -w0 app.pem)"

resource "random_bytes" "app_key" {
  length = 32
}

locals {
  generated_values = {
    APP_KEY = "base64:${random_bytes.app_key.base64}"
  }
}

resource "aws_ssm_parameter" "generated" {
  for_each = toset(var.generated)

  name  = "${var.prefix}/${each.key}"
  type  = "SecureString"
  value = local.generated_values[each.key]
}

resource "aws_ssm_parameter" "external" {
  for_each = toset(var.external)

  name  = "${var.prefix}/${each.key}"
  type  = "SecureString"
  value = "unset"

  lifecycle {
    ignore_changes = [value]
  }
}

variable "prefix" {
  description = "Parameter path prefix, starting with a slash, e.g. /sentinel-slop/prod"
  type        = string
}

variable "generated" {
  description = "Names Terraform generates values for (APP_KEY)."
  type        = list(string)
}

variable "external" {
  description = "Names whose values are written out of band."
  type        = list(string)
}

output "arns" {
  value = merge(
    { for k, p in aws_ssm_parameter.generated : k => p.arn },
    { for k, p in aws_ssm_parameter.external : k => p.arn },
  )
}

output "generated_values" {
  value     = local.generated_values
  sensitive = true
}
