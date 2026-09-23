import os
import requests

from shipping.config import DEFAULT_TIMEOUT


API_KEY = os.environ["DHL_API_KEY"]


def handle_rate_request(request):
    payload = request.json
    weight = payload.get("weight")
    destination = payload.get("destination")
    response = requests.post(
        "https://api.dhl.test/rates",
        json={"weight": weight, "destination": destination},
        headers={"Authorization": API_KEY},
        timeout=DEFAULT_TIMEOUT,
    )
    return response.json()


def handle_tracking_request(request):
    tracking_number = request.args.get("tracking_number")
    response = requests.get(f"https://api.dhl.test/track/{tracking_number}", timeout=DEFAULT_TIMEOUT)
    return response.json()
