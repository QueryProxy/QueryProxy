#!/usr/bin/env bash
#
# Upgrade test: data created on the previous release must survive a start of
# the image built from this checkout.
#
#   1. start OLD_IMAGE on a fresh data volume (migrations, first admin) and
#      create a team, a user and audit records on it;
#   2. stop it and start NEW_IMAGE on the SAME volume (the entrypoint runs the
#      new migrations);
#   3. check that the rows are still there, that `audit:verify` passes on the
#      chain that crosses the upgrade, and that /up answers.
#
# Usage:
#   docker build -t queryproxy:upgrade-candidate .
#   tests/Integration/upgrade/run.sh
#
# OLD_IMAGE (default queryproxy/queryproxy:0.3.3) and NEW_IMAGE (default
# queryproxy:upgrade-candidate) can be overridden. Needs docker and curl.

set -euo pipefail

OLD_IMAGE="${OLD_IMAGE:-queryproxy/queryproxy:0.3.3}"
NEW_IMAGE="${NEW_IMAGE:-queryproxy:upgrade-candidate}"
HOST_PORT="${HOST_PORT:-17432}"
# The app only answers to the host of APP_URL (default http://localhost:7432).

HERE="$(cd "$(dirname "$0")" && pwd)"
SUFFIX="$$"
VOLUME="qp-upgrade-data-${SUFFIX}"
OLD_NAME="qp-upgrade-old-${SUFFIX}"
NEW_NAME="qp-upgrade-new-${SUFFIX}"
ADMIN_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"

cleanup() {
    docker rm -f "$OLD_NAME" "$NEW_NAME" >/dev/null 2>&1 || true
    docker volume rm "$VOLUME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

logs_on_failure() {
    echo "--- logs of $1 ---" >&2
    docker logs --tail=80 "$1" >&2 || true
}

wait_for_up() {
    local name="$1"
    for _ in $(seq 1 90); do
        if curl -fsS "http://localhost:${HOST_PORT}/up" >/dev/null 2>&1; then
            return 0
        fi
        if [ "$(docker inspect -f '{{.State.Running}}' "$name")" != "true" ]; then
            echo "Container $name exited before /up answered." >&2
            logs_on_failure "$name"
            return 1
        fi
        sleep 2
    done
    echo "Timed out waiting for /up on $name." >&2
    logs_on_failure "$name"
    return 1
}

echo "== Starting the old release: $OLD_IMAGE"
docker volume create "$VOLUME" >/dev/null
docker run -d --name "$OLD_NAME" -p "127.0.0.1:${HOST_PORT}:7432" \
    -v "${VOLUME}:/var/www/html/storage/app" \
    -e QUERYPROXY_ADMIN_NAME="Upgrade Admin" \
    -e QUERYPROXY_ADMIN_EMAIL="upgrade-admin@example.com" \
    -e QUERYPROXY_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    "$OLD_IMAGE" >/dev/null
wait_for_up "$OLD_NAME"

echo "== Creating data on the old release"
docker exec -i "$OLD_NAME" php < "$HERE/seed.php"
docker exec "$OLD_NAME" php artisan audit:verify

# Stop (not kill) so SQLite is closed cleanly; the volume stays.
docker stop "$OLD_NAME" >/dev/null
docker rm "$OLD_NAME" >/dev/null

echo "== Starting this build on the same data: $NEW_IMAGE"
docker run -d --name "$NEW_NAME" -p "127.0.0.1:${HOST_PORT}:7432" \
    -v "${VOLUME}:/var/www/html/storage/app" \
    "$NEW_IMAGE" >/dev/null
wait_for_up "$NEW_NAME"

echo "== Checking the upgraded data"
docker exec -i "$NEW_NAME" php < "$HERE/check.php"

# audit:verify walks the chain from its first record, across the upgrade.
if ! docker exec "$NEW_NAME" php artisan audit:verify; then
    logs_on_failure "$NEW_NAME"
    exit 1
fi

# Smoke test: the health endpoint on the new image, and the login page renders.
curl -fsS "http://localhost:${HOST_PORT}/up" >/dev/null
status="$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${HOST_PORT}/login")"
if [ "$status" != "200" ]; then
    echo "GET /login answered $status, expected 200." >&2
    logs_on_failure "$NEW_NAME"
    exit 1
fi

echo "== Upgrade test passed: $OLD_IMAGE -> $NEW_IMAGE"
