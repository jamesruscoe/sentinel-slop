# EFS for the SQLite database, as Dog Desk does it. The access point makes every write www-data (uid/gid 33 in the
# php image) whatever user the container process runs as, and roots the task at /sentinel-data.

resource "aws_efs_file_system" "this" {
  creation_token = "${var.name}-data"
  encrypted      = true

  lifecycle_policy {
    transition_to_ia = "AFTER_30_DAYS"
  }

  tags = { Name = "${var.name}-data" }
}

resource "aws_efs_backup_policy" "this" {
  file_system_id = aws_efs_file_system.this.id

  backup_policy {
    status = "ENABLED"
  }
}

resource "aws_efs_access_point" "this" {
  file_system_id = aws_efs_file_system.this.id

  posix_user {
    uid = 33
    gid = 33
  }

  root_directory {
    path = "/sentinel-data"

    creation_info {
      owner_uid   = 33
      owner_gid   = 33
      permissions = "0750"
    }
  }

  tags = { Name = "${var.name}-data" }
}

resource "aws_efs_mount_target" "this" {
  count = length(var.subnet_ids)

  file_system_id  = aws_efs_file_system.this.id
  subnet_id       = var.subnet_ids[count.index]
  security_groups = [var.security_group_id]
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

output "file_system_id" {
  value = aws_efs_file_system.this.id
}

output "access_point_id" {
  value = aws_efs_access_point.this.id
}

output "file_system_arn" {
  value = aws_efs_file_system.this.arn
}

output "mount_target_ids" {
  value = aws_efs_mount_target.this[*].id
}
