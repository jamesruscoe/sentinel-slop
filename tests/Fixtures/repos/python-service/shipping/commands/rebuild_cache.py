import os

CACHE_DIR = os.getenv('SHIPPING_CACHE_DIR', '/tmp/shipping')


def rebuild():
    return CACHE_DIR
