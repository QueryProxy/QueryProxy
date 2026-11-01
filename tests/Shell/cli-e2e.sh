#!/usr/bin/env bash
# shellcheck disable=SC2016  # single quotes are intentional: PHP code
# End-to-end test of the `qp` command-line client (ADR-030) against a running
# QueryProxy server (ADR-024).
#
# It boots the application on a throw-away SQLite database, seeds a team, a
# requester, a DBA, an admin, a SQLite target connection and one personal
# access token each, starts the web server and a queue worker, and then drives
# the real `qp` binary:
#
#   approve   qp submit --wait  -> a DBA approves through the API -> exit 0 + CSV
#   reject    qp submit --wait  -> a DBA rejects through the API  -> exit 2
#   failure   a statement that fails when it runs                 -> exit 3
#   timeout   nobody decides                                      -> exit 4
#   ADR-024   an admin override, and a DBA approving their own request, are
#             both refused with 403 by the API.
#
# Usage:
#   tests/Shell/cli-e2e.sh [path/to/qp]
#
# The binary is taken from the first argument or $QP_BIN. Without either it is
# built from the sources in $QP_SRC (default: ../qp next to this repository;
# needs Go). Everything else lives in a temporary directory, never in
# database/database.sqlite - not even when the checkout has a cached
# configuration: a guard refuses to run unless the app points at the temporary
# database. Needs php, curl, jq. Exit 0 = all checks passed.
#
# Environment knobs: QP_E2E_PORT (default: a free port), QP_E2E_KEEP=1 (keep the
# temporary directory and print its path).

set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# A free port, unless one is given: a fixed one collides with whatever else runs.
PORT="${QP_E2E_PORT:-$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url("tcp://".stream_socket_get_name($s, false), PHP_URL_PORT);')}"
BASE_URL="http://127.0.0.1:${PORT}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/qp-e2e.XXXXXX")"

SERVER_PID=""
WORKER_PID=""
checks=0

cleanup() {
    # The built-in server forks workers that outlive their parent: stop those first.
    [ -n "$SERVER_PID" ] && { pkill -P "$SERVER_PID" 2>/dev/null; kill "$SERVER_PID" 2>/dev/null; }
    [ -n "$WORKER_PID" ] && kill "$WORKER_PID" 2>/dev/null
    wait 2>/dev/null
    if [ "${QP_E2E_KEEP:-0}" = 1 ]; then
        echo "kept: $WORK" >&2
    else
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

die() {
    echo "FAIL: $*" >&2
    for f in server.log worker.log; do
        if [ -s "$WORK/$f" ]; then
            echo "--- $f (tail) ---" >&2
            tail -n 25 "$WORK/$f" >&2
        fi
    done
    exit 1
}

need() { command -v "$1" >/dev/null 2>&1 || die "$1 is required"; }

# check <description> <command...>: runs the command, fails the run when it fails.
check() {
    local description="$1"
    shift
    checks=$((checks + 1))
    if "$@"; then
        echo "ok   $description"
    else
        die "$description"
    fi
}

need php
need curl
need jq

# ---- the qp binary ---------------------------------------------------------

QP="${1:-${QP_BIN:-}}"
if [ -z "$QP" ]; then
    QP_SRC="${QP_SRC:-$ROOT/../qp}"
    need go
    [ -f "$QP_SRC/go.mod" ] || die "no qp binary given and no qp sources at $QP_SRC (set QP_BIN or QP_SRC)"
    (cd "$QP_SRC" && go build -o "$WORK/qp" ./cmd/qp) || die "building qp failed"
    QP="$WORK/qp"
fi
[ -x "$QP" ] || die "$QP is not executable"

# ---- the application -------------------------------------------------------

cd "$ROOT" || exit 1

mkdir -p "$WORK/storage/framework/cache" "$WORK/storage/framework/sessions" "$WORK/storage/framework/views" \
    "$WORK/storage/logs" "$WORK/storage/app/private"
touch "$WORK/app.sqlite"

# Nothing below may touch the instance this checkout is configured for. A
# cached configuration (php artisan optimize / config:cache, or a container
# entrypoint that caches it) is never re-read from the environment, so the
# caches are pointed at files that do not exist, and DB_URL, which overrides
# DB_DATABASE, is dropped. The guard further down proves the result before
# anything is written.
unset DB_URL
export APP_CONFIG_CACHE="$WORK/no-config-cache.php" APP_ROUTES_CACHE="$WORK/no-routes-cache.php" \
    APP_EVENTS_CACHE="$WORK/no-events-cache.php"
export APP_ENV=local APP_DEBUG=false APP_URL="$BASE_URL"
APP_KEY="$(php artisan key:generate --show)" || die "cannot generate an APP_KEY"
export APP_KEY
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/app.sqlite"
export QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=array
export LOG_CHANNEL=stderr MAIL_MAILER=log
export QUERYPROXY_API_RATE_LIMIT=1000
# LARAVEL_STORAGE_PATH is read from $_ENV/$_SERVER, which the default
# variables_order ("GPCS") leaves empty; without this the stored results would
# land in (and be read from) the application's own storage directory.
export LARAVEL_STORAGE_PATH="$WORK/storage"
PHP_ENV_ORDER=(-d variables_order=EGPCS)

# The guard: boot the application exactly as the commands below will, and stop
# unless it points at the temporary database and storage. The seed creates an
# administrator with a known password, which must only ever exist there.
cat >"$WORK/guard.php" <<'PHP'
<?php
require getenv('QP_E2E_ROOT').'/vendor/autoload.php';
$app = require getenv('QP_E2E_ROOT').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo json_encode([
    'config_cached' => $app->configurationIsCached(),
    'default' => config('database.default'),
    'database' => config('database.connections.sqlite.database'),
    'storage' => $app->storagePath(),
]);
PHP
GUARD="$(QP_E2E_ROOT="$ROOT" php "${PHP_ENV_ORDER[@]}" "$WORK/guard.php" 2>"$WORK/guard.err")" \
    || { cat "$WORK/guard.err" >&2; die "cannot inspect the application configuration"; }
[ "$(jq -r .config_cached <<<"$GUARD")" = false ] || die "the application still reads a cached configuration; refusing to touch it"
[ "$(jq -r .default <<<"$GUARD")" = sqlite ] || die "the default database connection is not sqlite: $GUARD"
[ "$(jq -r .database <<<"$GUARD")" = "$WORK/app.sqlite" ] || die "the application database is not the temporary one: $GUARD"
[ "$(jq -r .storage <<<"$GUARD")" = "$WORK/storage" ] || die "the storage path is not the temporary one: $GUARD"

php "${PHP_ENV_ORDER[@]}" artisan migrate --force >"$WORK/migrate.log" 2>&1 || { cat "$WORK/migrate.log" >&2; die "migrate failed"; }

# The database the requests run against: a different file, outside the app.
target_script='
$pdo = new PDO("sqlite:" . getenv("QP_E2E_TARGET"));
$pdo->exec("CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
$pdo->exec("INSERT INTO people (id, name) VALUES (1, \"Ada\"), (2, \"Grace\"), (3, \"Linus\")");
'
QP_E2E_TARGET="$WORK/target.sqlite" php -r "$target_script" || die "cannot create the target database"

cat >"$WORK/seed.php" <<'PHP'
<?php
// Seeds the e2e world and prints the tokens as JSON.
require getenv('QP_E2E_ROOT').'/vendor/autoload.php';
$app = require getenv('QP_E2E_ROOT').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Api\TokenService;

$team = Team::create(['name' => 'E2E', 'slug' => 'e2e']);
$admin = User::create(['name' => 'Admin', 'email' => 'admin@e2e.test', 'password' => 'e2e-password-1', 'is_admin' => true]);
$dba = User::create(['name' => 'Dba', 'email' => 'dba@e2e.test', 'password' => 'e2e-password-2']);
$dev = User::create(['name' => 'Dev', 'email' => 'dev@e2e.test', 'password' => 'e2e-password-3']);
$team->users()->attach($dba, ['role' => 'dba']);
$team->users()->attach($dev, ['role' => 'developer']);

$connection = Connection::create([
    'team_id' => $team->id, 'name' => 'e2e-target', 'driver' => 'sqlite',
    'database' => getenv('QP_E2E_TARGET'), 'created_by' => $admin->id,
]);
$connection->grantedUsers()->attach($dev);

$tokens = app(TokenService::class);

echo json_encode([
    'connection_id' => $connection->id,
    'dev' => $tokens->create($dev, 'e2e', ['requests:submit', 'requests:read', 'results:read'], 1)->plainTextToken,
    'dba' => $tokens->create($dba, 'e2e', TokenService::scopes(), 1)->plainTextToken,
    'admin' => $tokens->create($admin, 'e2e', TokenService::scopes(), 1)->plainTextToken,
]);
PHP

SEED="$(QP_E2E_ROOT="$ROOT" QP_E2E_TARGET="$WORK/target.sqlite" php "${PHP_ENV_ORDER[@]}" "$WORK/seed.php" 2>"$WORK/seed.err")" \
    || { cat "$WORK/seed.err" >&2; die "seeding failed"; }

CONN_ID="$(jq -r .connection_id <<<"$SEED")"
DEV_TOKEN="$(jq -r .dev <<<"$SEED")"
DBA_TOKEN="$(jq -r .dba <<<"$SEED")"
ADMIN_TOKEN="$(jq -r .admin <<<"$SEED")"

PHP_CLI_SERVER_WORKERS=4 php "${PHP_ENV_ORDER[@]}" -S "127.0.0.1:${PORT}" -t public >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
php "${PHP_ENV_ORDER[@]}" artisan queue:work --queue=queries,default --sleep=1 --tries=1 >"$WORK/worker.log" 2>&1 &
WORKER_PID=$!

ready=0
for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "$BASE_URL/up" 2>/dev/null; then ready=1; break; fi
    sleep 0.5
done
[ "$ready" = 1 ] || die "the server did not come up on $BASE_URL"

# ---- helpers ----------------------------------------------------------------

export QP_URL="$BASE_URL"
export QP_TOKEN="$DEV_TOKEN"

# api <METHOD> <path> <token> [json-body]: prints "<http-status>" and leaves the
# body in $WORK/body.json.
api() {
    local method="$1" path="$2" token="$3" body="${4:-}"
    if [ -n "$body" ]; then
        curl -sS -o "$WORK/body.json" -w '%{http_code}' -X "$method" "$BASE_URL$path" \
            -H "Authorization: Bearer $token" -H 'Accept: application/json' \
            -H 'Content-Type: application/json' -d "$body"
    else
        curl -sS -o "$WORK/body.json" -w '%{http_code}' -X "$method" "$BASE_URL$path" \
            -H "Authorization: Bearer $token" -H 'Accept: application/json'
    fi
}

# wait_for_id <stderr-file>: the request id qp printed once it filed the request.
wait_for_id() {
    local id=""
    for _ in $(seq 1 60); do
        id="$(sed -n 's/^request #\([0-9][0-9]*\) filed.*/\1/p' "$1" 2>/dev/null | head -n 1)"
        [ -n "$id" ] && { echo "$id"; return 0; }
        sleep 0.5
    done
    return 1
}

# wait_for_exit <pid>: sets QP_RC to the exit code of a background qp, bounded in time.
wait_for_exit() {
    local pid="$1"
    for _ in $(seq 1 240); do
        if ! kill -0 "$pid" 2>/dev/null; then
            wait "$pid"
            QP_RC=$?
            return 0
        fi
        sleep 0.5
    done
    kill "$pid" 2>/dev/null
    QP_RC=124
    return 1
}

write_sql() { printf '%s\n' "$2" >"$WORK/$1.sql"; }

equals() { [ "$1" = "$2" ]; }
contains() { grep -qF -- "$2" "$1"; }
absent() { ! grep -qF -- "$2" "$1"; }

# ---- scenario 1: approve ----------------------------------------------------

write_sql select "SELECT id, name FROM people ORDER BY id"

"$QP" submit --conn e2e-target --file "$WORK/select.sql" --reason "e2e: approve" \
    --wait --poll-interval 1s --timeout 120s >"$WORK/a.out" 2>"$WORK/a.err" &
QP_PID=$!
ID_A="$(wait_for_id "$WORK/a.err")" || die "qp never filed the first request (see $WORK/a.err): $(cat "$WORK/a.err")"

"$QP" status "$ID_A" >"$WORK/a.status" 2>&1
STATUS_RC=$?
check "status of a waiting request exits 0" equals "$STATUS_RC" 0
check "status of a waiting request exits 0 and says pending" bash -c "grep -q pending '$WORK/a.status'"

check "an admin's approval is an override: refused with 403 and the web link" \
    equals "$(api POST "/api/v1/requests/$ID_A/approve" "$ADMIN_TOKEN")" 403
check "  ... and the answer carries override_url" jq -e '.override_url | strings | length > 0' "$WORK/body.json" >/dev/null

check "the requester cannot approve through the API (no approvals:write scope)" \
    equals "$(api POST "/api/v1/requests/$ID_A/approve" "$DEV_TOKEN")" 403

check "the DBA approves another user's request through the API" \
    equals "$(api POST "/api/v1/requests/$ID_A/approve" "$DBA_TOKEN")" 200

wait_for_exit "$QP_PID" || die "qp submit --wait did not finish after the approval"
check "qp submit --wait exits 0 once the request completed" equals "$QP_RC" 0
check "the result is on stdout as CSV (header)" contains "$WORK/a.out" "id,name"
check "the result is on stdout as CSV (rows)" bash -c "grep -q Ada '$WORK/a.out' && grep -q Linus '$WORK/a.out'"

"$QP" results "$ID_A" >"$WORK/a.results" 2>"$WORK/a.results.err"
check "qp results prints the same CSV" cmp -s "$WORK/a.out" "$WORK/a.results"

"$QP" results "$ID_A" --format json >"$WORK/a.json" 2>"$WORK/a.json.err"
check "qp results --format json is an array of objects keyed by column" \
    jq -e 'length == 3 and .[0].name == "Ada" and .[2].id == 3' "$WORK/a.json" >/dev/null

"$QP" results "$ID_A" --output "$WORK/a.file.csv" 2>/dev/null
check "qp results --output writes the file" cmp -s "$WORK/a.out" "$WORK/a.file.csv"

"$QP" status "$ID_A" >"$WORK/a.status2" 2>&1
STATUS_RC=$?
check "status of a finished request exits 0" equals "$STATUS_RC" 0
check "status of a finished request exits 0 and says completed" bash -c "grep -q completed '$WORK/a.status2'"

# ---- scenario 2: reject -----------------------------------------------------

"$QP" submit --conn "$CONN_ID" --file "$WORK/select.sql" --reason "e2e: reject" \
    --wait --poll-interval 1s --timeout 120s >"$WORK/r.out" 2>"$WORK/r.err" &
QP_PID=$!
ID_R="$(wait_for_id "$WORK/r.err")" || die "qp never filed the second request"

check "the DBA rejects through the API" \
    equals "$(api POST "/api/v1/requests/$ID_R/reject" "$DBA_TOKEN" '{"reason":"not today"}')" 200

wait_for_exit "$QP_PID" || die "qp submit --wait did not finish after the rejection"
check "qp submit --wait exits 2 for a rejected request" equals "$QP_RC" 2
check "  ... and shows the reason" contains "$WORK/r.err" "not today"
check "  ... and prints no result" equals "$(wc -c <"$WORK/r.out" | tr -d ' ')" 0

"$QP" status "$ID_R" >/dev/null 2>&1
check "qp status of a rejected request exits 2" equals "$?" 2

# ---- scenario 3: the statement fails when it runs ---------------------------

write_sql broken "SELECT * FROM table_that_does_not_exist"

"$QP" submit --conn e2e-target --file "$WORK/broken.sql" --reason "e2e: failure" \
    --wait --poll-interval 1s --timeout 120s >"$WORK/f.out" 2>"$WORK/f.err" &
QP_PID=$!
ID_F="$(wait_for_id "$WORK/f.err")" || die "qp never filed the failing request: $(cat "$WORK/f.err")"

check "the DBA approves the failing statement" \
    equals "$(api POST "/api/v1/requests/$ID_F/approve" "$DBA_TOKEN")" 200

wait_for_exit "$QP_PID" || die "qp submit --wait did not finish for the failing request"
check "qp submit --wait exits 3 when the request fails" equals "$QP_RC" 3

# ---- scenario 4: nobody decides ---------------------------------------------

"$QP" submit --conn e2e-target --file "$WORK/select.sql" --reason "e2e: timeout" \
    --wait --poll-interval 1s --timeout 4s >"$WORK/t.out" 2>"$WORK/t.err"
check "qp submit --wait exits 4 when --timeout elapses" equals "$?" 4

ID_T="$(sed -n 's/^request #\([0-9][0-9]*\) filed.*/\1/p' "$WORK/t.err" | head -n 1)"
check "  ... and names the request that is still waiting" test -n "$ID_T"
check "the requester withdraws it" equals "$(api POST "/api/v1/requests/$ID_T/cancel" "$DEV_TOKEN")" 200

# ---- scenario 5: ADR-024, a token cannot approve its owner's own request ----

ID_S="$(QP_TOKEN="$DBA_TOKEN" "$QP" submit --conn e2e-target --file "$WORK/select.sql" --reason "e2e: self" 2>/dev/null)"
check "submit without --wait prints just the request id" bash -c "[ '$ID_S' -gt 0 ] 2>/dev/null"
check "a DBA cannot approve their own request through the API" \
    equals "$(api POST "/api/v1/requests/$ID_S/approve" "$DBA_TOKEN")" 403
check "the DBA withdraws it" equals "$(api POST "/api/v1/requests/$ID_S/cancel" "$DBA_TOKEN")" 200

# ---- scenario 6: errors are reported, the token stays secret ----------------

BAD_TOKEN="999|qp_thisIsNotARealToken"
QP_TOKEN="$BAD_TOKEN" "$QP" status "$ID_A" >"$WORK/bad.out" 2>"$WORK/bad.err"
check "a wrong token exits 1" equals "$?" 1
check "  ... with the server's 401" contains "$WORK/bad.err" "401"
check "  ... and the token is not echoed" absent "$WORK/bad.err" "thisIsNotARealToken"

"$QP" submit --conn e2e-target --file "$WORK/select.sql" --reason "x" --wait --timeout 0s --poll-interval 1s \
    --url "http://127.0.0.1:1" >"$WORK/down.out" 2>"$WORK/down.err"
check "an unreachable server exits 1" equals "$?" 1

for f in a.out a.err r.out r.err f.out f.err t.out t.err a.status a.status2 a.json; do
    [ -f "$WORK/$f" ] || continue
    check "no token in $f" bash -c "! grep -qF -e '$DEV_TOKEN' -e '$DBA_TOKEN' -e '$ADMIN_TOKEN' '$WORK/$f'"
done

echo
echo "all $checks checks passed"
