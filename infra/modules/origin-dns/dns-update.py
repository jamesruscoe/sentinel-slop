"""
Point the CloudFront origin record at the task that just started (Dog Desk's dns-update Lambda).

Triggered by EventBridge on an ECS task state change to RUNNING. Finds the task's ENI, reads its public IP
and UPSERTs RECORD_NAME. CloudFront reaches the task through that record.
"""

import json
import os
import time

import boto3

ecs = boto3.client("ecs")
ec2 = boto3.client("ec2")
route53 = boto3.client("route53")

HOSTED_ZONE_ID = os.environ["HOSTED_ZONE_ID"]
RECORD_NAME = os.environ["RECORD_NAME"]


def handler(event, context):
    print(json.dumps(event))

    detail = event.get("detail", {})
    task_arn = detail.get("taskArn", "")

    if detail.get("desiredStatus") == "STOPPED":
        print(f"Ignoring draining task: {task_arn}")
        return

    tasks = ecs.describe_tasks(cluster=detail["clusterArn"], tasks=[task_arn])["tasks"]
    if not tasks:
        print(f"Task not found: {task_arn}")
        return

    eni_id = None
    for attachment in tasks[0].get("attachments", []):
        if attachment.get("type") == "ElasticNetworkInterface":
            for kv in attachment.get("details", []):
                if kv.get("name") == "networkInterfaceId":
                    eni_id = kv["value"]

    if not eni_id:
        print("No ENI found on task")
        return

    public_ip = None
    for _ in range(6):
        eni = ec2.describe_network_interfaces(NetworkInterfaceIds=[eni_id])["NetworkInterfaces"][0]
        public_ip = eni.get("Association", {}).get("PublicIp")
        if public_ip:
            break
        time.sleep(3)

    if not public_ip:
        raise RuntimeError(f"No public IP on ENI {eni_id}")

    print(f"Updating {RECORD_NAME} -> {public_ip}")
    route53.change_resource_record_sets(
        HostedZoneId=HOSTED_ZONE_ID,
        ChangeBatch={
            "Comment": f"Task {task_arn}",
            "Changes": [
                {
                    "Action": "UPSERT",
                    "ResourceRecordSet": {
                        "Name": RECORD_NAME,
                        "Type": "A",
                        "TTL": 60,
                        "ResourceRecords": [{"Value": public_ip}],
                    },
                }
            ],
        },
    )

    return {"record": RECORD_NAME, "ip": public_ip}
