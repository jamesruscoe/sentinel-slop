import time

from shipping.repositories.shipment_repository import ShipmentRepository


def run_export_manifest(batch_size=100):
    repository = ShipmentRepository()
    started = time.time()
    processed = 0
    for shipment in repository.pending(batch_size):
        repository.mark("export_manifest", shipment)
        processed += 1
    return {"processed": processed, "seconds": time.time() - started}
