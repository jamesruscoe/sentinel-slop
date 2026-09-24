# One Redis node for Horizon's queues and the cache. Engine "redis" 7.x on purpose: ElastiCache creates Valkey
# only through replication groups (CreateCacheCluster rejects engine = "valkey"), and a single-node cluster is
# the cheapest shape. Reachable only from the tasks' security group; no auth token, because ElastiCache requires
# in-transit TLS with one and Laravel would then need a tls:// REDIS_URL. Flip both together if the VPC
# boundary ever stops being enough.

resource "aws_elasticache_subnet_group" "this" {
  name       = var.name
  subnet_ids = var.subnet_ids
}

resource "aws_elasticache_cluster" "this" {
  cluster_id               = substr(var.name, 0, 40)
  engine                   = "redis"
  engine_version           = var.engine_version
  node_type                = var.node_type
  num_cache_nodes          = 1
  port                     = 6379
  subnet_group_name        = aws_elasticache_subnet_group.this.name
  security_group_ids       = [var.security_group_id]
  maintenance_window       = "sun:04:30-sun:05:30"
  snapshot_retention_limit = 0
  apply_immediately        = false
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

variable "node_type" {
  type = string
}

variable "engine_version" {
  type = string
}

output "address" {
  value = aws_elasticache_cluster.this.cache_nodes[0].address
}
