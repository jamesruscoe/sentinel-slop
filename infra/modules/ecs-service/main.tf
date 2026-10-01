# The one Fargate Spot service running the whole of Sentinel Slop (CONTAINER_ROLE=all: nginx, php-fpm, both queue
# workers and the scheduler under supervisord), with the SQLite database on EFS. The task definition Terraform
# registers carries the image_tag it is given; the deploy pipeline registers new revisions with the commit SHA and
# updates the service, so the service's task_definition is ignored here after creation.

locals {
  environment = [for k, v in merge(var.environment, { CONTAINER_ROLE = var.role }) : { name = k, value = v }]
  secrets     = [for k, arn in var.secrets : { name = k, valueFrom = arn }]

  container = {
    name        = var.role
    image       = var.image
    essential   = true
    environment = local.environment
    secrets     = local.secrets
    stopTimeout = var.stop_timeout
    portMappings = [{
      containerPort = var.container_port
      protocol      = "tcp"
    }]
    mountPoints = [{
      sourceVolume  = "data"
      containerPath = var.efs_mount_path
      readOnly      = false
    }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = var.log_group_name
        "awslogs-region"        = var.region
        "awslogs-stream-prefix" = var.role
      }
    }
  }
}

resource "aws_ecs_task_definition" "this" {
  family                   = var.name
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.cpu
  memory                   = var.memory
  execution_role_arn       = var.execution_role_arn
  task_role_arn            = var.task_role_arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = var.architecture
  }

  ephemeral_storage {
    size_in_gib = 21
  }

  volume {
    name = "data"

    efs_volume_configuration {
      file_system_id     = var.efs_file_system_id
      transit_encryption = "ENABLED"

      authorization_config {
        access_point_id = var.efs_access_point_id
        iam             = "DISABLED"
      }
    }
  }

  container_definitions = jsonencode([local.container])
}

resource "aws_ecs_service" "this" {
  name             = var.name
  cluster          = var.cluster_id
  task_definition  = aws_ecs_task_definition.this.arn
  desired_count    = var.desired_count
  platform_version = "1.4.0"

  capacity_provider_strategy {
    capacity_provider = "FARGATE_SPOT"
    weight            = 1
  }

  # Stop the old task before starting the new one. Two tasks must never overlap: SQLite on EFS has one writer, and
  # the new task's `recover-interrupted --force` would fail a scan the old one was still running.
  deployment_minimum_healthy_percent = 0
  deployment_maximum_percent         = 100

  # ECS Exec: `aws ecs execute-command` opens a shell in the running task (sqlite3, artisan tinker).
  enable_execute_command = true

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  network_configuration {
    subnets          = var.subnet_ids
    security_groups  = var.security_group_ids
    assign_public_ip = true
  }

  lifecycle {
    ignore_changes = [task_definition]
  }
}

variable "name" {
  type = string
}

variable "role" {
  description = "Passed to the image as CONTAINER_ROLE; `all` runs everything in one container."
  type        = string
}

variable "cluster_id" {
  type = string
}

variable "image" {
  type = string
}

variable "cpu" {
  type = number
}

variable "memory" {
  type = number
}

variable "architecture" {
  type = string
}

variable "desired_count" {
  type = number
}

variable "subnet_ids" {
  type = list(string)
}

variable "security_group_ids" {
  type = list(string)
}

variable "execution_role_arn" {
  type = string
}

variable "task_role_arn" {
  type = string
}

variable "log_group_name" {
  type = string
}

variable "region" {
  type = string
}

variable "environment" {
  type = map(string)
}

variable "secrets" {
  description = "env name => Secrets Manager ARN"
  type        = map(string)
}

variable "container_port" {
  type = number
}

variable "efs_file_system_id" {
  type = string
}

variable "efs_access_point_id" {
  type = string
}

variable "efs_mount_path" {
  type = string
}

variable "stop_timeout" {
  description = "Seconds between SIGTERM and SIGKILL (Fargate allows at most 120; Spot gives two minutes' notice)."
  type        = number
  default     = 120
}

output "service_name" {
  value = aws_ecs_service.this.name
}

output "task_family" {
  value = aws_ecs_task_definition.this.family
}

output "task_definition_arn" {
  value = aws_ecs_task_definition.this.arn
}
