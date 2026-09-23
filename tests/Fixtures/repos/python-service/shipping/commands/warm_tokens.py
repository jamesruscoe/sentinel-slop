import os


def warm():
    return os.environ.get('SHIPPING_TOKEN_TTL', '3600')
