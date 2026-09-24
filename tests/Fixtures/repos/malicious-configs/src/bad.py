import os  # noqa: F401


def risky(value):
    try:
        return int(value)
    except:  # noqa
        pass
