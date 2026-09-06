#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Zero-config bootstrap: env file, app key, SQLite database, migrations.
if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "$DB_FILE" ]; then
    mkdir -p "$(dirname "$DB_FILE")"
    touch "$DB_FILE"
fi

php artisan migrate --force

if [ "${QUERYPROXY_SEED_DEMO:-false}" = "true" ] && [ ! -f storage/app/.demo-seeded ]; then
    php artisan db:seed --force
    touch storage/app/.demo-seeded
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache

exec "$@"
