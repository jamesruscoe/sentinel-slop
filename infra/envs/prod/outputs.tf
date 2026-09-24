output "site_url" {
  value = local.app_url
}

output "alb_dns_name" {
  value = module.alb.dns_name
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "cluster_name" {
  value = module.cluster.cluster_name
}

output "service_names" {
  value = {
    web       = module.web.service_name
    worker    = module.worker.service_name
    scheduler = module.scheduler.service_name
  }
}

output "task_families" {
  value = {
    web       = module.web.task_family
    worker    = module.worker.task_family
    scheduler = module.scheduler.task_family
  }
}

output "deploy_role_arn" {
  description = "Assumed by GitHub Actions through OIDC (AWS_DEPLOY_ROLE_ARN repository variable)."
  value       = module.iam.deploy_role_arn
}

output "secret_arns" {
  description = "Fill the external ones before the first deploy: aws secretsmanager put-secret-value --secret-id <arn> --secret-string ..."
  value       = module.secrets.arns
}

output "db_address" {
  value = module.rds.address
}

output "redis_address" {
  value = module.redis.address
}

output "public_subnet_ids" {
  description = "The deploy pipeline runs the migration task here."
  value       = module.network.public_subnet_ids
}

output "tasks_security_group_id" {
  value = module.network.tasks_security_group_id
}
