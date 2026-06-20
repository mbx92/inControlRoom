#!/bin/sh

APP_DIR="${APP_DIR:-/var/www/html}"
ROLE="${CONTAINER_ROLE:-app}"

log() {
    echo "[$(date -Iseconds)] $*"
}

log "start-container: role=${ROLE}"

cd "${APP_DIR}" || {
    log "FATAL: cannot cd to ${APP_DIR}"
    exit 1
}

if [ -z "${APP_KEY:-}" ] && [ "${ROLE}" != "terminal-proxy" ]; then
    log "FATAL: APP_KEY is required. Set it in Coolify Environment Variables."
    log "Generate one locally with: php artisan key:generate --show"
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
    || log "WARNING: mkdir failed"

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-${APP_DIR}/storage/app/database/database.sqlite}"
    mkdir -p "$(dirname "${DB_PATH}")"
    touch "${DB_PATH}" || log "WARNING: cannot touch ${DB_PATH}"
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

php artisan storage:link --force 2>/dev/null || true

run_migrations() {
    log "running migrations (DB=${DB_CONNECTION:-sqlite} host=${DB_HOST:-n/a})..."
    if php artisan migrate --force --no-interaction; then
        log "migrations completed"
    else
        log "WARNING: migration failed - check DB_HOST/DB_PASSWORD in Coolify env"
        log "WARNING: app will still start, but database features will not work"
    fi
}

case "${ROLE}" in
    app)
        if [ "${AUTO_RUN_MIGRATIONS:-true}" = "true" ]; then
            run_migrations &
        fi

        log "starting supervisord (nginx + php-fpm on :8080)"
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;

    queue)
        log "starting queue worker (will retry on DB errors)"
        while true; do
            php artisan queue:work \
                --verbose \
                --tries="${QUEUE_WORKER_TRIES:-1}" \
                --timeout="${QUEUE_WORKER_TIMEOUT:-0}" \
                --sleep="${QUEUE_WORKER_SLEEP:-1}" \
                --queue="${QUEUE_WORKER_QUEUE:-default}" \
                2>&1 || log "queue worker exited, retrying in 5s..."
            sleep 5
        done
        ;;

    scheduler)
        log "starting scheduler loop (every ${SCHEDULER_INTERVAL:-60}s)"
        while true; do
            php artisan schedule:run --verbose --no-interaction 2>&1 || true
            sleep "${SCHEDULER_INTERVAL:-60}"
        done
        ;;

    terminal-proxy)
        export SSH_TERMINAL_PROXY_HOST="${SSH_TERMINAL_PROXY_HOST:-0.0.0.0}"
        export SSH_TERMINAL_PROXY_PORT="${SSH_TERMINAL_PROXY_PORT:-8078}"
        log "starting terminal-proxy on ${SSH_TERMINAL_PROXY_HOST}:${SSH_TERMINAL_PROXY_PORT}"
        exec node scripts/ssh-terminal-proxy.mjs
        ;;

    *)
        exec "$@"
        ;;
esac
