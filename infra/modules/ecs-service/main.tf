# A Fargate service running the Sentinel Slop image in one role. The task definition Terraform registers
# carries the bootstrap image tag; the deploy pipeline registers new revisions with the commit SHA and
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
    portMappings = var.container_port == null ? [] : [{
      containerPort = var.container_port
      protocol      = "tcp"
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

  container_definitions = jsonencode([local.container])
}

resource "aws_ecs_service" "this" {
  name            = var.name
  cluster         = var.cluster_id
  task_definition = aws_ecs_task_definition.this.arn
  desired_count   = var.desired_count
  launch_type     = "FARGATE"

  deployment_minimum_healthy_percent = var.deployment_minimum_healthy_percent
  deployment_maximum_percent         = var.deployment_maximum_percent

  # ECS Exec: lets `aws ssm start-session` port-forward through a running task to RDS and Redis, which have
  # no public endpoint. Nothing inside the container changes; the agent is injected by Fargate.
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

  dynamic "load_balancer" {
    for_each = var.target_group_arn == null ? [] : [var.target_group_arn]

    content {
      target_group_arn = load_balancer.value
      container_name   = var.role
      container_port   = var.container_port
    }
  }

  health_check_grace_period_seconds = var.target_group_arn == null ? null : 60

  lifecycle {
    ignore_changes = [task_definition]
  }
}

variable "name" {
  type = string
}

variable "role" {
  description = "web | worker | scheduler | reverb, passed to the image as CONTAINER_ROLE"
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
  type    = number
  default = null
}

variable "target_group_arn" {
  type    = string
  default = null
}

variable "deployment_minimum_healthy_percent" {
  description = "100 with maximum 200 starts the new task before stopping the old (web). 0 with maximum 100 stops the old task first, for a role that must never run twice (the worker: a second worker's forced recovery fails the first one's scan)."
  type        = number
  default     = 100
}

variable "deployment_maximum_percent" {
  type    = number
  default = 200
}

variable "stop_timeout" {
  description = "Seconds between SIGTERM and SIGKILL (Fargate allows at most 120)."
  type        = number
  default     = 30
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
