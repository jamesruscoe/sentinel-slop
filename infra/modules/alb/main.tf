# Application load balancer: 443 with the ACM certificate to the web target group, 80 redirected.
# Idle timeout 120 s leaves room for a websocket service (Reverb) behind a second rule later.

# The security group comes from the network module, which is also what the tasks admit on 8080.
resource "aws_lb" "this" {
  name                       = substr(var.name, 0, 32)
  load_balancer_type         = "application"
  security_groups            = [var.security_group_id]
  subnets                    = var.subnet_ids
  idle_timeout               = 120
  drop_invalid_header_fields = true
}

resource "aws_lb_target_group" "web" {
  name        = "${substr(var.name, 0, 28)}-web"
  port        = 8080
  protocol    = "HTTP"
  target_type = "ip"
  vpc_id      = var.vpc_id

  deregistration_delay = 30

  # During a rolling deploy two web tasks answer for a minute or two, each with its own Vite build. A page
  # served by the new task names asset files the old task does not have (an HTML 404 refused as "text/html"),
  # so a browser sticks to one task for an hour; long enough to outlast any overlap, short enough to matter to nobody.
  stickiness {
    type            = "lb_cookie"
    cookie_duration = 3600
    enabled         = true
  }

  health_check {
    path                = "/up"
    matcher             = "200"
    interval            = 30
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.this.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }
}

resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

variable "name" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "subnet_ids" {
  type = list(string)
}

variable "certificate_arn" {
  type = string
}

variable "security_group_id" {
  type = string
}

output "dns_name" {
  value = aws_lb.this.dns_name
}

output "zone_id" {
  value = aws_lb.this.zone_id
}

output "target_group_arn" {
  value = aws_lb_target_group.web.arn
}
