#!/bin/sh

APP_DIR="${APP_DIR:-/var/www/html}"
ROLE="${CONTAINER_ROLE:-app}"

echo "==> start-container: role=${ROLE}" >&2

cd "${APP_DIR}" || {
    echo "FATAL: cannot cd to ${APP_DIR}" >&2
    exit 1
}

if [ -z "${APP_KEY:-}" ] && [ "${ROLE}" != "terminal-proxy" ]; then
    echo "FATAL: APP_KEY is required." >&2
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
    bootstrap/cache \
    || echo "WARNING: mkdir failed" >&2

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-${APP_DIR}/storage/app/database/database.sqlite}"
    mkdir -p "$(dirname "${DB_PATH}")"
    touch "${DB_PATH}" || echo "WARNING: cannot touch ${DB_PATH}" >&2
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

php artisan storage:link --force 2>/dev/null || true

case "${ROLE}" in
    app)
        echo "==> app: running migrations..." >&2
        if [ "${AUTO_RUN_MIGRATIONS:-true}" = "true" ]; then
            php artisan migrate --force --no-interaction 2>&1 || \
                echo "WARNING: Database migration failed, continuing anyway." >&2
        fi

        echo "==> app: starting supervisord (nginx + php-fpm)" >&2
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;

    queue)
        echo "==> queue: starting worker" >&2
        exec php artisan queue:work \
            --verbose \
            --tries="${QUEUE_WORKER_TRIES:-1}" \
            --timeout="${QUEUE_WORKER_TIMEOUT:-0}" \
            --sleep="${QUEUE_WORKER_SLEEP:-1}" \
            --queue="${QUEUE_WORKER_QUEUE:-default}"
        ;;

    scheduler)
        echo "==> scheduler: running every ${SCHEDULER_INTERVAL:-60}s" >&2
        while true; do
            php artisan schedule:run --verbose --no-interaction 2>&1 || true
            sleep "${SCHEDULER_INTERVAL:-60}"
        done
        ;;

    terminal-proxy)
        export SSH_TERMINAL_PROXY_HOST="${SSH_TERMINAL_PROXY_HOST:-0.0.0.0}"
        echo "==> terminal-proxy: starting on ${SSH_TERMINAL_PROXY_HOST}:${SSH_TERMINAL_PROXY_PORT}" >&2
        exec node scripts/ssh-terminal-proxy.mjs
        ;;

    *)
        exec "$@"
        ;;
esac
