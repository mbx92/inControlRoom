#!/bin/sh

set -e

APP_DIR="${APP_DIR:-/var/www/html}"
ROLE="${CONTAINER_ROLE:-app}"

cd "${APP_DIR}"

if [ -z "${APP_KEY:-}" ] && [ "${ROLE}" != "terminal-proxy" ]; then
    echo "APP_KEY is required." >&2
    exit 1
fi

mkdir -p \
    storage/app \
    storage/app/public \
    storage/app/database \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-${APP_DIR}/storage/app/database/database.sqlite}"
    mkdir -p "$(dirname "${DB_PATH}")"
    touch "${DB_PATH}"
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true

case "${ROLE}" in
    app)
        if [ "${AUTO_RUN_MIGRATIONS:-true}" = "true" ]; then
            php artisan migrate --force --no-interaction || \
                echo "WARNING: Database migration failed, continuing anyway." >&2
        fi

        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;

    queue)
        exec php artisan queue:work \
            --verbose \
            --tries="${QUEUE_WORKER_TRIES:-1}" \
            --timeout="${QUEUE_WORKER_TIMEOUT:-0}" \
            --sleep="${QUEUE_WORKER_SLEEP:-1}" \
            --queue="${QUEUE_WORKER_QUEUE:-default}"
        ;;

    scheduler)
        while true; do
            php artisan schedule:run --verbose --no-interaction
            sleep "${SCHEDULER_INTERVAL:-60}"
        done
        ;;

    terminal-proxy)
        export SSH_TERMINAL_PROXY_HOST="${SSH_TERMINAL_PROXY_HOST:-0.0.0.0}"
        exec node scripts/ssh-terminal-proxy.mjs
        ;;

    *)
        exec "$@"
        ;;
esac
