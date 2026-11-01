#!/usr/bin/env bash
#
# Helm chart smoke test (ADR-001/ADR-007 amendments, work item 040-08).
#
# Installs deploy/helm/queryproxy into the cluster kubectl points at (a kind
# cluster in CI, see .github/workflows/helm.yml) with the application database
# on PostgreSQL, so web, worker and scheduler really run as separate pods, and
# checks that:
#
#   1. the migration hook, then the three Deployments, come up (scheduler: 1);
#   2. /up answers 200 through the Service;
#   3. only the worker pod runs `queue:work`, the web pod does not;
#   4. a request submitted by one DBA and approved by another is executed by
#      the worker and ends up `completed`, and `audit:verify` passes;
#   5. a second `helm upgrade` (pre-upgrade migration hook again) keeps the
#      APP_KEY and the service answering;
#   6. a `helm upgrade` that changes the configuration followed by a
#      `helm rollback` puts the previous configuration back.
#
# A small PostgreSQL pod serves as the application database and, as a second
# database, as the target the sample request runs against. That server belongs
# to this script, not to the chart.
#
# The requests go to localhost, not 127.0.0.1: QueryProxy only answers to the
# host of APP_URL (here http://localhost:<port>), anything else gets 400.
#
# Usage:
#   docker build -t queryproxy:helm-smoke .
#   kind create cluster && kind load docker-image queryproxy:helm-smoke
#   tests/Integration/helm/smoke.sh
#
# IMAGE (default queryproxy:helm-smoke), RELEASE, NAMESPACE and CHART can be
# overridden. Needs kubectl, helm, curl and openssl. The release and namespace
# are removed again on exit.

set -euo pipefail

IMAGE="${IMAGE:-queryproxy:helm-smoke}"
RELEASE="${RELEASE:-queryproxy}"
NAMESPACE="${NAMESPACE:-queryproxy-smoke}"
HOST_PORT="${HOST_PORT:-7432}"
TIMEOUT="${TIMEOUT:-10m}"

HERE="$(cd "$(dirname "$0")" && pwd)"
CHART="${CHART:-$HERE/../../../deploy/helm/queryproxy}"

IMAGE_REPOSITORY="${IMAGE%:*}"
IMAGE_TAG="${IMAGE##*:}"

# The release name contains the chart name, so the resources are called
# <release>-web, <release>-worker, ... and the Service just <release>.
FULLNAME="$RELEASE"
case "$RELEASE" in
    *queryproxy*) ;;
    *) FULLNAME="${RELEASE}-queryproxy" ;;
esac

DB_PASSWORD="$(openssl rand -hex 16)"
ADMIN_PASSWORD="$(openssl rand -hex 16)"
PORT_FORWARD_PID=""

k() { kubectl --namespace "$NAMESPACE" "$@"; }

diagnostics() {
    echo "--- cluster state in $NAMESPACE ---" >&2
    k get pods,jobs,deployments,svc -o wide >&2 || true
    for pod in $(k get pods -o name 2>/dev/null); do
        echo "--- describe $pod ---" >&2
        k describe "$pod" 2>&1 | tail -30 >&2 || true
        echo "--- logs $pod ---" >&2
        k logs "$pod" --all-containers --tail=60 >&2 || true
    done
}

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        diagnostics
    fi
    if [ -n "$PORT_FORWARD_PID" ]; then
        kill "$PORT_FORWARD_PID" >/dev/null 2>&1 || true
    fi
    helm uninstall "$RELEASE" --namespace "$NAMESPACE" >/dev/null 2>&1 || true
    kubectl delete namespace "$NAMESPACE" --wait=false >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT

# Extra arguments (more --set flags) are appended.
helm_install() {
    helm upgrade --install "$RELEASE" "$CHART" \
        --namespace "$NAMESPACE" \
        --wait --timeout "$TIMEOUT" \
        --set image.repository="$IMAGE_REPOSITORY" \
        --set image.tag="$IMAGE_TAG" \
        --set image.pullPolicy=IfNotPresent \
        --set config.appUrl="http://localhost:${HOST_PORT}" \
        --set config.executionTimeout=60 \
        --set database.connection=pgsql \
        --set database.host=smoke-postgres \
        --set database.sslMode=disable \
        --set persistence.enabled=false \
        --set web.replicas=2 \
        --set worker.replicas=1 \
        --set secrets.dbPassword="$DB_PASSWORD" \
        --set admin.email=smoke-admin@example.com \
        --set secrets.adminPassword="$ADMIN_PASSWORD" \
        "$@"
}

# PHP piped into `php` inside the web pod, like tests/Integration/upgrade/run.sh.
seed_php() {
    cat <<'PHP'
<?php

use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$team = Team::create(['name' => 'Smoke Team', 'slug' => 'smoke-team']);

$submitter = User::forceCreate([
    'name' => 'Smoke Submitter',
    'email' => 'smoke-submitter@example.com',
    'password' => Hash::make(bin2hex(random_bytes(16))),
]);
$reviewer = User::forceCreate([
    'name' => 'Smoke Reviewer',
    'email' => 'smoke-reviewer@example.com',
    'password' => Hash::make(bin2hex(random_bytes(16))),
]);

// Two DBAs: nobody reviews their own request.
$team->users()->attach($submitter, ['role' => 'dba']);
$team->users()->attach($reviewer, ['role' => 'dba']);

$connection = Connection::create([
    'team_id' => $team->id,
    'name' => 'smoke-target',
    'driver' => 'pgsql',
    'host' => 'smoke-postgres',
    'port' => '5432',
    'database' => 'smoke_target',
    'username' => 'queryproxy',
    'password' => getenv('SMOKE_TARGET_PASSWORD'),
    'options' => ['tls' => ['mode' => 'disable']],
]);

$request = app(RequestSubmissionService::class)->submit(
    $submitter,
    $connection,
    'SELECT id, name FROM items ORDER BY id',
    'helm smoke test',
);

app(ApprovalService::class)->approve($request, $reviewer);

echo 'REQUEST_ID='.$request->id.PHP_EOL;
PHP
}

status_php() {
    cat <<'PHP'
<?php

use App\Models\QueryRequest;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo QueryRequest::findOrFail((int) getenv('REQUEST_ID'))->status->value;
PHP
}

start_port_forward() {
    if [ -n "$PORT_FORWARD_PID" ]; then
        kill "$PORT_FORWARD_PID" >/dev/null 2>&1 || true
    fi
    kubectl --namespace "$NAMESPACE" port-forward "svc/${FULLNAME}" "${HOST_PORT}:80" >/dev/null 2>&1 &
    PORT_FORWARD_PID=$!
}

wait_for_up() {
    for _ in $(seq 1 60); do
        if curl -fsS "http://localhost:${HOST_PORT}/up" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    echo "Timed out waiting for /up through the port-forward." >&2
    return 1
}

echo "== Namespace and PostgreSQL (application database + sample target)"
kubectl create namespace "$NAMESPACE"

k apply -f - <<EOF
apiVersion: v1
kind: Pod
metadata:
  name: smoke-postgres
  labels:
    app: smoke-postgres
spec:
  containers:
    - name: postgres
      image: postgres:17-alpine
      env:
        - name: POSTGRES_USER
          value: queryproxy
        - name: POSTGRES_PASSWORD
          value: "${DB_PASSWORD}"
        - name: POSTGRES_DB
          value: queryproxy
      ports:
        - containerPort: 5432
      # TCP, not the socket: the image's init server listens on the socket only.
      readinessProbe:
        exec:
          command: ["pg_isready", "-h", "127.0.0.1", "-U", "queryproxy"]
        periodSeconds: 2
---
apiVersion: v1
kind: Service
metadata:
  name: smoke-postgres
spec:
  selector:
    app: smoke-postgres
  ports:
    - port: 5432
EOF
k wait --for=condition=Ready pod/smoke-postgres --timeout=180s

# QueryProxy refuses a target that is its own application database, so the
# sample target is a second database on the same server.
k exec smoke-postgres -- psql -v ON_ERROR_STOP=1 -U queryproxy -d queryproxy -c 'CREATE DATABASE smoke_target'
k exec smoke-postgres -- psql -v ON_ERROR_STOP=1 -U queryproxy -d smoke_target \
    -c 'CREATE TABLE items (id integer PRIMARY KEY, name text NOT NULL)' \
    -c "INSERT INTO items VALUES (1, 'alpha'), (2, 'beta'), (3, 'gamma')"

echo "== helm lint"
helm lint "$CHART" \
    --set database.connection=pgsql --set database.host=smoke-postgres \
    --set persistence.enabled=false --set secrets.dbPassword=x

echo "== helm install (migration hook, then web, worker, scheduler)"
helm_install

echo "== Deployments"
scheduler_replicas="$(k get deployment "${FULLNAME}-scheduler" -o jsonpath='{.spec.replicas}')"
if [ "$scheduler_replicas" != "1" ]; then
    echo "Scheduler has $scheduler_replicas replicas, expected 1." >&2
    exit 1
fi
scheduler_strategy="$(k get deployment "${FULLNAME}-scheduler" -o jsonpath='{.spec.strategy.type}')"
if [ "$scheduler_strategy" != "Recreate" ]; then
    echo "Scheduler strategy is $scheduler_strategy, expected Recreate." >&2
    exit 1
fi
k rollout status "deployment/${FULLNAME}-web" --timeout=120s
k rollout status "deployment/${FULLNAME}-worker" --timeout=120s
k rollout status "deployment/${FULLNAME}-scheduler" --timeout=120s

echo "== /up through the Service"
start_port_forward
wait_for_up
status="$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${HOST_PORT}/up")"
if [ "$status" != "200" ]; then
    echo "GET /up answered $status, expected 200." >&2
    exit 1
fi
status="$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${HOST_PORT}/login")"
if [ "$status" != "200" ]; then
    echo "GET /login answered $status, expected 200." >&2
    exit 1
fi

# In-cluster clients (Prometheus scraping /metrics) use the Service name as Host;
# the chart adds it to TRUSTED_HOSTS.
status="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${FULLNAME}.${NAMESPACE}.svc" "http://localhost:${HOST_PORT}/up")"
if [ "$status" != "200" ]; then
    echo "GET /up with the Service name as Host answered $status, expected 200." >&2
    exit 1
fi

echo "== Role split: queue:work runs in the worker pod only"
if k exec "deployment/${FULLNAME}-web" -- sh -c "ps -o args | grep -c '[q]ueue:work'" >/dev/null 2>&1; then
    echo "The web pod runs a queue worker; QUERYPROXY_RUN_WORKER=false was not honoured." >&2
    exit 1
fi
k exec "deployment/${FULLNAME}-worker" -- sh -c "ps -o args | grep '[q]ueue:work'" >/dev/null
k exec "deployment/${FULLNAME}-scheduler" -- sh -c "ps -o args | grep '[s]chedule:work'" >/dev/null

echo "== Submitting and approving a sample request"
request_id="$(seed_php | k exec -i "deployment/${FULLNAME}-web" -- env SMOKE_TARGET_PASSWORD="$DB_PASSWORD" php | sed -n 's/^REQUEST_ID=//p')"

if [ -z "$request_id" ]; then
    echo "The sample request was not created." >&2
    exit 1
fi
echo "Request $request_id queued; waiting for the worker."

request_status=""
for _ in $(seq 1 60); do
    request_status="$(status_php | k exec -i "deployment/${FULLNAME}-web" -- env REQUEST_ID="$request_id" php)"
    case "$request_status" in
        completed | failed | rejected | cancelled) break ;;
    esac
    sleep 2
done

if [ "$request_status" != "completed" ]; then
    echo "Request $request_id ended as '${request_status}', expected 'completed'." >&2
    exit 1
fi
echo "Request $request_id completed on the worker."

k exec "deployment/${FULLNAME}-web" -- php artisan audit:verify

echo "== helm upgrade keeps APP_KEY and the service"
key_before="$(k get secret "$FULLNAME" -o jsonpath='{.data.APP_KEY}')"
helm_install
key_after="$(k get secret "$FULLNAME" -o jsonpath='{.data.APP_KEY}')"
if [ -z "$key_before" ] || [ "$key_before" != "$key_after" ]; then
    echo "APP_KEY changed across helm upgrade." >&2
    exit 1
fi
# The web pods may have been replaced; a forward bound to one of them is gone.
start_port_forward
wait_for_up
status="$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${HOST_PORT}/up")"
if [ "$status" != "200" ]; then
    echo "GET /up answered $status after the upgrade, expected 200." >&2
    exit 1
fi

echo "== helm rollback restores the previous configuration"
# Hook resources are not versioned by Helm, so the settings must live in
# ordinary release resources for a rollback to bring them back.
helm_install --set config.logLevel=debug
level="$(k get configmap "$FULLNAME" -o jsonpath='{.data.LOG_LEVEL}')"
if [ "$level" != "debug" ]; then
    echo "The upgrade did not change LOG_LEVEL (got '$level')." >&2
    exit 1
fi
helm rollback "$RELEASE" --namespace "$NAMESPACE" --wait --timeout "$TIMEOUT"
level="$(k get configmap "$FULLNAME" -o jsonpath='{.data.LOG_LEVEL}')"
if [ "$level" != "info" ]; then
    echo "helm rollback left LOG_LEVEL at '$level', expected 'info'." >&2
    exit 1
fi
key_rolled="$(k get secret "$FULLNAME" -o jsonpath='{.data.APP_KEY}')"
if [ "$key_before" != "$key_rolled" ]; then
    echo "APP_KEY changed across helm rollback." >&2
    exit 1
fi
start_port_forward
wait_for_up

echo "== Helm smoke test passed ($IMAGE)"
