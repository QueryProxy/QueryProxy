#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Zero-config bootstrap: env file, app key, SQLite database, migrations.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# APP_KEY resolution, in order of preference:
#   1. An APP_KEY environment variable (recommended for production).
#   2. A key file on the shared database volume, so the app, worker and
#      scheduler containers all encrypt/decrypt with the same key and the
#      key survives container rebuilds.
#   3. Generate one once and persist it to that key file.
KEY_FILE="${QUERYPROXY_KEY_FILE:-/var/www/html/database/.app_key}"

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        (umask 077 && echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE")
        echo "[entrypoint] Generated APP_KEY and stored it at ${KEY_FILE} (shared volume)."
        echo "[entrypoint] For production, prefer an explicit APP_KEY environment variable."
        echo "[entrypoint] Generate one with: docker run --rm <image> php artisan key:generate --show"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "$DB_FILE" ]; then
    mkdir -p "$(dirname "$DB_FILE")"
    touch "$DB_FILE"
fi

php artisan migrate --force

if [ "${QUERYPROXY_SEED_DEMO:-false}" = "true" ] && [ ! -f storage/app/.demo-seeded ]; then
    if [ "${APP_ENV:-production}" = "production" ] && [ "${QUERYPROXY_SEED_DEMO_FORCE:-false}" != "true" ]; then
        echo "[entrypoint] QUERYPROXY_SEED_DEMO=true ignored: refusing to seed demo accounts while APP_ENV=production." >&2
        echo "[entrypoint] Set QUERYPROXY_SEED_DEMO_FORCE=true as well if this instance really should carry demo data." >&2
    else
        php artisan db:seed --force
        touch storage/app/.demo-seeded
    fi
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache

exec "$@"
