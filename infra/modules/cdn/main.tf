# CloudFront in front of the task, as Dog Desk does it, instead of a load balancer. CloudFront terminates TLS with
# a us-east-1 certificate and talks plain HTTP to origin.<domain>, an A record the origin-dns Lambda points at the
# running task's public IP. Nothing is cached except Vite's hashed assets; every header, cookie and query string
# reaches the origin (GitHub's webhook signature header, Livewire's, the session cookie).

terraform {
  required_providers {
    aws = {
      source                = "hashicorp/aws"
      configuration_aliases = [aws.us_east_1]
    }
  }
}

data "aws_route53_zone" "this" {
  name = var.hosted_zone_name
}

data "aws_cloudfront_cache_policy" "disabled" {
  name = "Managed-CachingDisabled"
}

data "aws_cloudfront_cache_policy" "optimized" {
  name = "Managed-CachingOptimized"
}

data "aws_cloudfront_origin_request_policy" "all_viewer" {
  name = "Managed-AllViewer"
}

resource "aws_acm_certificate" "this" {
  provider          = aws.us_east_1
  domain_name       = var.domain_name
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_route53_record" "validation" {
  for_each = {
    for dvo in aws_acm_certificate.this.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  zone_id         = data.aws_route53_zone.this.zone_id
  name            = each.value.name
  type            = each.value.type
  ttl             = 60
  records         = [each.value.record]
  allow_overwrite = true
}

resource "aws_acm_certificate_validation" "this" {
  provider                = aws.us_east_1
  certificate_arn         = aws_acm_certificate.this.arn
  validation_record_fqdns = [for r in aws_route53_record.validation : r.fqdn]
}

# Managed by the origin-dns Lambda on every task start; the placeholder only exists so CloudFront has a name.
resource "aws_route53_record" "origin" {
  zone_id = data.aws_route53_zone.this.zone_id
  name    = "origin.${var.domain_name}"
  type    = "A"
  ttl     = 60
  records = ["127.0.0.1"]

  lifecycle {
    ignore_changes = [records]
  }
}

resource "aws_cloudfront_distribution" "this" {
  enabled         = true
  aliases         = [var.domain_name]
  price_class     = "PriceClass_100"
  http_version    = "http2and3"
  is_ipv6_enabled = true
  comment         = var.name

  origin {
    domain_name = aws_route53_record.origin.fqdn
    origin_id   = "task"

    custom_origin_config {
      http_port                = var.origin_port
      https_port               = 443
      origin_protocol_policy   = "http-only"
      origin_ssl_protocols     = ["TLSv1.2"]
      origin_read_timeout      = 60
      origin_keepalive_timeout = 5
    }
  }

  default_cache_behavior {
    target_origin_id         = "task"
    viewer_protocol_policy   = "redirect-to-https"
    allowed_methods          = ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"]
    cached_methods           = ["GET", "HEAD"]
    compress                 = true
    cache_policy_id          = data.aws_cloudfront_cache_policy.disabled.id
    origin_request_policy_id = data.aws_cloudfront_origin_request_policy.all_viewer.id
  }

  # Vite output: content-hashed file names, safe to cache for a year.
  ordered_cache_behavior {
    path_pattern           = "/build/*"
    target_origin_id       = "task"
    viewer_protocol_policy = "redirect-to-https"
    allowed_methods        = ["GET", "HEAD"]
    cached_methods         = ["GET", "HEAD"]
    compress               = true
    cache_policy_id        = data.aws_cloudfront_cache_policy.optimized.id
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    acm_certificate_arn      = aws_acm_certificate_validation.this.certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }
}

resource "aws_route53_record" "site" {
  for_each = toset(["A", "AAAA"])

  zone_id = data.aws_route53_zone.this.zone_id
  name    = var.domain_name
  type    = each.key

  alias {
    name                   = aws_cloudfront_distribution.this.domain_name
    zone_id                = aws_cloudfront_distribution.this.hosted_zone_id
    evaluate_target_health = false
  }
}

variable "name" {
  type = string
}

variable "domain_name" {
  type = string
}

variable "hosted_zone_name" {
  type = string
}

variable "origin_port" {
  type = number
}

output "hosted_zone_id" {
  value = data.aws_route53_zone.this.zone_id
}

output "origin_fqdn" {
  value = aws_route53_record.origin.fqdn
}

output "distribution_domain_name" {
  value = aws_cloudfront_distribution.this.domain_name
}
