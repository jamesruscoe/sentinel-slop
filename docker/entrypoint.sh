#!/bin/sh
# One image, four roles. CONTAINER_ROLE selects what this container runs:
#   web        php-fpm + nginx under supervisord (port 8080)
#   worker     Horizon on the scans and default queues, after recovering interrupted scans
#   scheduler  the Laravel scheduler (sentinel:prune daily, sentinel:recover-interrupted every five minutes)
#   reverb     the websocket server (optional; the scan page polls without it)
#   anything else is run as-is as www-data, e.g. `php artisan migrate --force` from a one-off ECS task.
set -eu

cd /var/www/html

SCANS="${SENTINEL_SCAN_STORAGE_PATH:-/tmp/sentinel/scans}"
mkdir -p "$SCANS" storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data "$SCANS" storage bootstrap/cache

# Configuration and routes are cached per container start: the config holds the injected secrets, and Livewire's
# route prefix is a hash of APP_KEY, so neither can be baked into the image.
gosu www-data php artisan config:cache --no-ansi >/dev/null
gosu www-data php artisan route:cache --no-ansi >/dev/null

case "${CONTAINER_ROLE:-web}" in
    web)
        exec supervisord -c /etc/supervisor/supervisord.conf
        ;;
    worker)
        # A scan whose worker died cannot resume on another disk. With one worker task, everything still
        # running at start was ours and is failed now; with several, only stale scans are (the scheduled run).
        if [ "${SENTINEL_SOLE_WORKER:-true}" = "true" ]; then
            gosu www-data php artisan sentinel:recover-interrupted --force --no-ansi
        else
            gosu www-data php artisan sentinel:recover-interrupted --no-ansi
        fi
        exec gosu www-data php artisan horizon
        ;;
    scheduler)
        exec gosu www-data php artisan schedule:work
        ;;
    reverb)
        exec gosu www-data php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    *)
        exec gosu www-data "$@"
        ;;
esac
