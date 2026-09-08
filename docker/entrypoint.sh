#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Everything that must survive a container replacement lives in one directory,
# so a plain `docker run` needs a single volume:
#   -v queryproxy-data:/var/www/html/storage/app
# It holds the SQLite database, the generated APP_KEY and the result files.
DATA_DIR=/var/www/html/storage/app

# Volumes created by QueryProxy <= 0.1.1 kept the database and key under
# /var/www/html/database; keep using them so upgrades don't lose data.
LEGACY_DIR=/var/www/html/database

# Zero-config bootstrap: env file, app key, SQLite database, migrations.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# APP_KEY resolution, in order of preference:
#   1. An APP_KEY environment variable (recommended for production).
#   2. A key file on the data volume, so the key survives container rebuilds
#      and every process (web, worker, scheduler) uses the same one.
#   3. Generate one once and persist it to that key file.
if [ -n "${QUERYPROXY_KEY_FILE:-}" ]; then
    KEY_FILE="$QUERYPROXY_KEY_FILE"
elif [ -s "$LEGACY_DIR/.app_key" ]; then
    KEY_FILE="$LEGACY_DIR/.app_key"
else
    KEY_FILE="$DATA_DIR/.app_key"
fi

if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        mkdir -p "$(dirname "$KEY_FILE")"
        (umask 077 && echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE")
        # stderr, so `docker run --rm <image> php artisan key:generate --show`
        # stays capturable in a command substitution.
        echo "[entrypoint] Generated APP_KEY and stored it at ${KEY_FILE} (data volume)." >&2
        echo "[entrypoint] For production, prefer an explicit APP_KEY environment variable." >&2
        echo "[entrypoint] Generate one with: docker run --rm queryproxy/queryproxy php artisan key:generate --show" >&2
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ -z "${DB_DATABASE:-}" ] && [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    if [ -f "$LEGACY_DIR/database.sqlite" ]; then
        DB_DATABASE="$LEGACY_DIR/database.sqlite"
    else
        DB_DATABASE="$DATA_DIR/database.sqlite"
    fi
    export DB_DATABASE
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# Only the container that serves the app migrates, seeds and warms caches.
# One-off commands (`docker run --rm <image> php artisan ...`) and split
# worker/scheduler containers just inherit the environment resolved above.
if [ "${1:-}" = "supervisord" ]; then
    php artisan migrate --force

    # First-boot administrator, so a fresh instance has someone to log in as.
    # --if-none makes this a no-op once the instance has any user, so leaving
    # the variables set across restarts is harmless (still: unset them once the
    # account exists, they stay readable in `docker inspect`).
    if [ -n "${QUERYPROXY_ADMIN_EMAIL:-}" ] && [ -n "${QUERYPROXY_ADMIN_PASSWORD:-}" ]; then
        php artisan queryproxy:create-admin --if-none --no-interaction \
            --name="${QUERYPROXY_ADMIN_NAME:-Admin}" \
            --email="$QUERYPROXY_ADMIN_EMAIL" \
            --password="$QUERYPROXY_ADMIN_PASSWORD" \
            || echo "[entrypoint] Could not create the administrator account; fix the values above and run 'php artisan queryproxy:create-admin' in the container." >&2
    fi

    if [ "${QUERYPROXY_SEED_DEMO:-false}" = "true" ] && [ ! -f "$DATA_DIR/.demo-seeded" ]; then
        if [ "${APP_ENV:-production}" = "production" ] && [ "${QUERYPROXY_SEED_DEMO_FORCE:-false}" != "true" ]; then
            echo "[entrypoint] QUERYPROXY_SEED_DEMO=true ignored: refusing to seed demo accounts while APP_ENV=production." >&2
            echo "[entrypoint] Set QUERYPROXY_SEED_DEMO_FORCE=true as well if this instance really should carry demo data." >&2
        else
            php artisan db:seed --force
            touch "$DATA_DIR/.demo-seeded"
        fi
    fi

    php artisan storage:link >/dev/null 2>&1 || true
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
