import time

from shipping.repositories.shipment_repository import ShipmentRepository


def run_purge_labels(batch_size=100):
    repository = ShipmentRepository()
    started = time.time()
    processed = 0
    for shipment in repository.pending(batch_size):
        repository.mark("purge_labels", shipment)
        processed += 1
    return {"processed": processed, "seconds": time.time() - started}
