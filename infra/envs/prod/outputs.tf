output "site_url" {
  value = local.app_url
}

output "cloudfront_domain_name" {
  value = module.cdn.distribution_domain_name
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "cluster_name" {
  value = module.cluster.cluster_name
}

output "service_name" {
  value = module.app.service_name
}

output "task_family" {
  value = module.app.task_family
}

output "deploy_role_arn" {
  description = "Assumed by GitHub Actions through OIDC (AWS_DEPLOY_ROLE_ARN repository variable)."
  value       = module.iam.deploy_role_arn
}

output "secret_arns" {
  description = "Fill the external ones before the first deploy: aws secretsmanager put-secret-value --secret-id <arn> --secret-string ..."
  value       = module.secrets.arns
}

output "efs_file_system_id" {
  value = module.efs.file_system_id
}
