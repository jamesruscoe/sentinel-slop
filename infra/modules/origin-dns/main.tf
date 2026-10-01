# Dog Desk's DNS trick: when a task in the cluster reaches RUNNING, EventBridge invokes a Lambda that reads the
# task's public IP and UPSERTs origin.<domain>, which CloudFront uses as its origin. Replaces a load balancer for
# a single task; the cost is a minute or so of 502s while a new task starts and the 60 s TTL expires.

data "archive_file" "this" {
  type        = "zip"
  source_file = "${path.module}/dns-update.py"
  output_path = "${path.module}/.build/dns-update.zip"
}

data "aws_iam_policy_document" "assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["lambda.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "this" {
  name               = "${var.name}-dns-update"
  assume_role_policy = data.aws_iam_policy_document.assume.json
}

resource "aws_iam_role_policy_attachment" "logs" {
  role       = aws_iam_role.this.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole"
}

data "aws_iam_policy_document" "this" {
  statement {
    actions   = ["ecs:DescribeTasks"]
    resources = ["*"]

    condition {
      test     = "ArnEquals"
      variable = "ecs:cluster"
      values   = [var.cluster_arn]
    }
  }

  statement {
    actions   = ["ec2:DescribeNetworkInterfaces"]
    resources = ["*"]
  }

  statement {
    actions   = ["route53:ChangeResourceRecordSets"]
    resources = ["arn:aws:route53:::hostedzone/${var.hosted_zone_id}"]
  }
}

resource "aws_iam_role_policy" "this" {
  name   = "dns-update"
  role   = aws_iam_role.this.id
  policy = data.aws_iam_policy_document.this.json
}

resource "aws_cloudwatch_log_group" "this" {
  name              = "/aws/lambda/${var.name}-dns-update"
  retention_in_days = 14
}

resource "aws_lambda_function" "this" {
  function_name    = "${var.name}-dns-update"
  filename         = data.archive_file.this.output_path
  source_code_hash = data.archive_file.this.output_base64sha256
  handler          = "dns-update.handler"
  runtime          = "python3.12"
  timeout          = 30
  role             = aws_iam_role.this.arn

  environment {
    variables = {
      HOSTED_ZONE_ID = var.hosted_zone_id
      RECORD_NAME    = var.record_name
    }
  }

  depends_on = [aws_cloudwatch_log_group.this]
}

resource "aws_cloudwatch_event_rule" "this" {
  name        = "${var.name}-task-running"
  description = "A task in ${var.name} reached RUNNING"

  event_pattern = jsonencode({
    source      = ["aws.ecs"]
    detail-type = ["ECS Task State Change"]
    detail = {
      clusterArn    = [var.cluster_arn]
      lastStatus    = ["RUNNING"]
      desiredStatus = ["RUNNING"]
    }
  })
}

resource "aws_cloudwatch_event_target" "this" {
  rule      = aws_cloudwatch_event_rule.this.name
  target_id = "dns-update"
  arn       = aws_lambda_function.this.arn
}

resource "aws_lambda_permission" "this" {
  statement_id  = "AllowEventBridge"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.this.function_name
  principal     = "events.amazonaws.com"
  source_arn    = aws_cloudwatch_event_rule.this.arn
}

variable "name" {
  type = string
}

variable "cluster_arn" {
  type = string
}

variable "hosted_zone_id" {
  type = string
}

variable "record_name" {
  description = "The origin record to keep pointed at the task, e.g. origin.example.com"
  type        = string
}
