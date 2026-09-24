# MySQL 8.4 on the smallest Graviton instance, single AZ, encrypted, daily backups kept a week,
# deletion protection on. Findings are ~0.5 MB per large scan and the 30-day retention purge trims
# snippets, so 20 GB with autoscaling to 100 GB lasts years.

resource "aws_db_subnet_group" "this" {
  name       = var.name
  subnet_ids = var.subnet_ids
}

resource "aws_db_parameter_group" "this" {
  name   = var.name
  family = "mysql8.4"

  parameter {
    name  = "character_set_server"
    value = "utf8mb4"
  }

  parameter {
    name  = "collation_server"
    value = "utf8mb4_unicode_ci"
  }
}

resource "aws_db_instance" "this" {
  identifier = var.name

  engine               = "mysql"
  engine_version       = "8.4"
  instance_class       = var.instance_class
  parameter_group_name = aws_db_parameter_group.this.name

  allocated_storage     = 20
  max_allocated_storage = 100
  storage_type          = "gp3"
  storage_encrypted     = true

  db_name  = var.db_name
  username = var.username
  password = var.password
  port     = 3306

  db_subnet_group_name   = aws_db_subnet_group.this.name
  vpc_security_group_ids = [var.security_group_id]
  publicly_accessible    = false
  multi_az               = false

  backup_retention_period    = 7
  backup_window              = "02:00-03:00"
  maintenance_window         = "Sun:03:30-Sun:04:30"
  auto_minor_version_upgrade = true
  apply_immediately          = false

  deletion_protection       = true
  skip_final_snapshot       = false
  final_snapshot_identifier = "${var.name}-final"
  copy_tags_to_snapshot     = true

  performance_insights_enabled = false
}

variable "name" {
  type = string
}

variable "subnet_ids" {
  type = list(string)
}

variable "security_group_id" {
  type = string
}

variable "instance_class" {
  type = string
}

variable "db_name" {
  type = string
}

variable "username" {
  type = string
}

variable "password" {
  type      = string
  sensitive = true
}

output "address" {
  value = aws_db_instance.this.address
}

output "arn" {
  value = aws_db_instance.this.arn
}
