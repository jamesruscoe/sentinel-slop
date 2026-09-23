class ShipmentRepository:
    def __init__(self):
        self._rows = []

    def pending(self, limit):
        return self._rows[:limit]

    def mark(self, job, shipment):
        shipment["last_job"] = job
        return shipment
