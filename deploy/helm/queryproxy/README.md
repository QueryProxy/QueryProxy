# QueryProxy Helm chart and HA reference architecture

This chart runs QueryProxy on Kubernetes. It uses the one published image and, with an external application database,
starts it as separate **web**, **worker** and **scheduler** Deployments that scale independently (ADR-001 and ADR-007
amendments, 2026-10-26). It is the work item 040-08 of the v0.4.0 plan.

```text
                       Ingress / LoadBalancer
                                |
                        Service (port 80 -> 7432)
                                |
             +------------------+------------------+
             |                                     |
   web  x N  (nginx + php-fpm)           worker  x M   (queue:work)        scheduler  x 1  (schedule:work)
   RUN_WORKER=false, RUN_SCHEDULER=false  RUN_WORKER=true                    RUN_SCHEDULER=true
             |                                     |                                |
             +------------------+------------------+--------------------------------+
                                |
                 external PostgreSQL / MySQL  (users, requests, queue, sessions, audit chain)
                                |
                       S3 bucket (result files)            target databases (reached by the workers)
```

All Deployments run the same image and read the same ConfigMap and Secret. A `helm.sh/hook: pre-install,pre-upgrade`
Job runs `php artisan migrate --force` before any of them is created or replaced. Only the Job (and, with a
chart-managed Secret, its private copy of the credentials) is a hook; the ConfigMap, Secret and ServiceAccount are
ordinary release resources, so `helm rollback` restores the previous configuration.

## Two modes (the SQLite exception)

| `database.connection`      | Rendered                                               | Replicas                                   |
| -------------------------- | ------------------------------------------------------ | ------------------------------------------ |
| `sqlite` (default)         | one Deployment (`web`) running web, worker, scheduler  | exactly 1, `Recreate`, PVC at `storage/app` |
| `pgsql`, `mysql`, `mariadb` | `web`, `worker`, `scheduler` and the migration Job     | web and worker free, scheduler always 1    |

SQLite is one file on one volume, so it cannot be shared between pods. The chart enforces that: `helm template` and
`helm install` **fail** for SQLite with `web.replicas > 1`, with `worker.autoscaling.enabled`, or without
`persistence.enabled`. SQLite mode is meant for evaluation and small teams; it is the docker-compose container on
Kubernetes.

The role split (the web Deployment runs neither worker nor scheduler, the worker Deployment only the queue worker, the
scheduler Deployment only the scheduler) is the decision of the ADR-007 Amendment of 2026-10-26. That amendment carries
an **exception for SQLite** (user decision 2026-10-31): a SQLite file cannot be shared between pods, so with SQLite the
chart installs one all-in-one Deployment with a single replica that runs web, worker and scheduler, as in
docker-compose (`QUERYPROXY_RUN_WORKER` and `QUERYPROXY_RUN_SCHEDULER` are both `true` on it). The role split applies
only with an external application database, which is the setup `values-ha.yaml` describes. In SQLite mode there is
therefore no `worker` or `scheduler` Deployment in `helm template`.

## Install from the OCI registry

Every release tag publishes the chart to GitHub Container Registry as
`oci://ghcr.io/queryproxy/charts/queryproxy`; the chart version equals the release version (the `v` prefix is
dropped). Use these commands instead of the local path in the sections below:

```bash
helm upgrade --install queryproxy oci://ghcr.io/queryproxy/charts/queryproxy --version 0.4.1 \
  --namespace queryproxy --create-namespace \
  --set admin.email=admin@example.com --set secrets.adminPassword="$(openssl rand -hex 16)"

# Or fetch it first, e.g. to read values-ha.yaml:
helm pull oci://ghcr.io/queryproxy/charts/queryproxy --version 0.4.1 --untar
```

The chart is signed keyless with [cosign](https://github.com/sigstore/cosign) (Sigstore, GitHub OIDC identity) from
the release workflow, the same way the images are. Verify it with cosign v3 or later before installing; the identity
must be the release workflow of this repository at a version tag, issued by GitHub Actions:

```bash
cosign verify \
  --certificate-identity-regexp '^https://github\.com/QueryProxy/QueryProxy/\.github/workflows/release\.yml@refs/tags/v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/queryproxy/charts/queryproxy:0.4.1
```

Replace `0.4.1` with the version you are installing. The command is the one in `SECURITY.md`, with the chart
repository in place of the image; the release workflow runs it against the pushed digest before it finishes.

## Quick start (SQLite, one pod)

```bash
helm upgrade --install queryproxy deploy/helm/queryproxy \
  --namespace queryproxy --create-namespace \
  --set admin.email=admin@example.com --set secrets.adminPassword="$(openssl rand -hex 16)"

kubectl -n queryproxy port-forward svc/queryproxy 7432:80   # then open http://localhost:7432
```

`config.appUrl` defaults to `http://localhost:7432`. QueryProxy only answers to the host of `APP_URL` (plus
`config.trustedHosts`), so set it to the address users really type, usually the https URL of the Ingress. The probes
send that host too.

## HA install (`values-ha.yaml`)

1. Provision PostgreSQL (a managed service or an operator such as CloudNativePG) with a database and role for
   QueryProxy, and an S3 bucket for result files (private).
2. Create the Secret `queryproxy-secrets` (see [Secrets](#secrets)) **before** installing; the migration hook needs it.
3. Edit `values-ha.yaml` (every `example` value) and install:

```bash
helm upgrade --install queryproxy deploy/helm/queryproxy \
  -f deploy/helm/queryproxy/values-ha.yaml --namespace queryproxy --create-namespace
```

What the profile sets: external PostgreSQL with `sslMode: require`, S3 result storage, 2 web and 2 worker replicas
spread over zones, one scheduler, Ingress with TLS, a PodDisruptionBudget for web and worker, and a NetworkPolicy.

What you size yourself:

- **PostgreSQL** is the single stateful dependency. It holds the queue, sessions and the append-only audit chain
  (ADR-020), so use a replicated, backed-up service. QueryProxy opens connections per pod (web: PHP-FPM children,
  worker: `config.workerProcesses`); put a pooler in front for large worker counts.
- **Workers** run approved queries. `config.executionTimeout` (seconds, default 300) bounds one query; a worker pod gets
  `executionTimeout + 30` seconds to finish a running query when it is stopped (`terminationGracePeriodSeconds`), which
  matches supervisord's `stopwaitsecs` in the image. Parallelism is `worker.replicas x config.workerProcesses`.
- **Scheduler** is fixed at one replica with the `Recreate` strategy. Two schedulers would run every scheduled task
  (retention pruning, reminders) twice.

## Secrets

The pods need `APP_KEY` (all pods must share it), the database password and, when used, the Redis password, the
metrics token, the first-boot admin password and S3 keys.

**Chart-managed (evaluation).** Leave `secrets.existingSecret` empty. The chart creates a Secret from `secrets.*`.
`APP_KEY` is generated on the first install and found again by `lookup` on every upgrade. That Secret is an ordinary
release resource marked `helm.sh/resource-policy: keep`, so it is **not** removed by `helm uninstall`: connection
credentials in the database cannot be decrypted without the key, so back it up. The migration Job runs before that
Secret exists on a first install, so it reads a hook-only copy (`<fullname>-migrate`, same values) that Helm removes
when the hook has finished. The pods restart when the values of a chart-managed Secret change
(`checksum/secret`); with `secrets.existingSecret` restart them yourself after rotating it.
Tools that render without cluster access (Argo CD, `helm template`) cannot `lookup`: give them `secrets.appKey` or an
existing Secret.

**Your own Secret (production).** Set `secrets.existingSecret`. Expected keys (names configurable in `secrets.keys`):
`APP_KEY`, `DB_PASSWORD`, and as needed `REDIS_PASSWORD`, `QUERYPROXY_METRICS_TOKEN`, `QUERYPROXY_ADMIN_PASSWORD`,
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`. Example with the External Secrets Operator (the API version depends on
your operator release):

```yaml
apiVersion: external-secrets.io/v1
kind: ExternalSecret
metadata:
  name: queryproxy-secrets
  namespace: queryproxy
spec:
  refreshInterval: 1h
  secretStoreRef:
    kind: ClusterSecretStore
    name: vault-backend
  target:
    name: queryproxy-secrets
    creationPolicy: Owner
  data:
    - secretKey: APP_KEY
      remoteRef: {key: queryproxy/production, property: app_key}
    - secretKey: DB_PASSWORD
      remoteRef: {key: queryproxy/production, property: db_password}
    - secretKey: AWS_ACCESS_KEY_ID
      remoteRef: {key: queryproxy/production, property: s3_access_key_id}
    - secretKey: AWS_SECRET_ACCESS_KEY
      remoteRef: {key: queryproxy/production, property: s3_secret_access_key}
```

Apply it and wait for `kubectl -n queryproxy get secret queryproxy-secrets` before `helm install`.

**Secrets as files.** `secrets.mountAsFiles=true` mounts the Secret at `secrets.mountPath` (default
`/run/secrets/queryproxy`) and sets `APP_KEY_FILE`, `DB_PASSWORD_FILE`, ... instead of plain variables; the image's
entrypoint (`docker/secret-files.sh`) reads them, so the values never appear in `kubectl describe pod` or the pod
spec. `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` have no `_FILE` form and stay environment variables; avoid them
with IRSA / Workload Identity (`results.s3.useSecretCredentials=false`, annotate the ServiceAccount).

## Migrations

With an external database the migration Job (`migration.enabled`, default true) runs before every install and upgrade,
so web and worker pods of the new version never meet an old schema. If it fails, the release does not proceed
(`backoffLimit`, `activeDeadlineSeconds` in `migration.*`). The pods' entrypoint still runs `migrate --force` on start;
after the hook that is a no-op. To move an existing SQLite installation to PostgreSQL first, stop it and use
`php artisan queryproxy:migrate-appdb --to=<dsn>`, then install this chart with the new `database.*` values.

The Job gets the ConfigMap's settings inline and runs as the namespace's default ServiceAccount (no token mounted), so
neither the ConfigMap nor the ServiceAccount has to exist before the hook. With `secrets.existingSecret` the Job reads
that Secret, so it must exist before `helm install`.

## Autoscaling the workers

`worker.autoscaling.enabled=true` renders a `HorizontalPodAutoscaler` (autoscaling/v2) on the external metric
`queryproxy_queue_depth` (ADR-022) with `queue="queries"`, target `queueDepthPerPod` waiting jobs per pod. It is off by
default because it needs:

1. `metrics.enabled=true` with `secrets.metricsToken` (or `QUERYPROXY_METRICS_ALLOWED_IPS` in `config.extraEnv`);
   `/metrics` is off without one of them.
2. Prometheus scraping `/metrics` (bearer token) with a `namespace` label on the series, **through the Service name**.
   QueryProxy only answers to trusted `Host` names (TrustHosts), so a scrape of a pod IP (what a ServiceMonitor does by
   default) gets `400`. The chart adds `<fullname>`, `<fullname>.<namespace>`, `.svc` and `.svc.cluster.local` to
   `TRUSTED_HOSTS`; scrape one of them. One target is enough, the value comes from the database:

   ```yaml
   # prometheus additionalScrapeConfigs
   - job_name: queryproxy
     metrics_path: /metrics
     authorization:
       credentials_file: /etc/prometheus/secrets/queryproxy-metrics/token
     static_configs:
       - targets: ['queryproxy.queryproxy.svc:80']
         labels: {namespace: queryproxy}
   ```

   With the Prometheus Operator, a ServiceMonitor works if a `relabelings` entry sets `__address__` to
   `queryproxy.queryproxy.svc:80`.
3. A metrics adapter serving it as an external metric; the prometheus-adapter rule is in the comment above
   `worker.autoscaling` in `values.yaml`. The metric is computed from the database, so every pod reports the same value
   and the rule uses `max()`.

Scale-down is deliberately slow (600 s stabilization, one pod per 120 s): stopping a pod that runs a query waits for it.

## Network, availability and hardening

- **Probes** call `GET /up` with the `Host` header of `config.appUrl`. A startup probe (5 min budget) covers the first
  boot, when the entrypoint migrates and warms the caches. Worker and scheduler pods also run nginx and PHP-FPM (the
  image always starts them under supervisord; the roles are switched with `QUERYPROXY_RUN_WORKER` and
  `QUERYPROXY_RUN_SCHEDULER`), which is what lets the same probe work on every role.
- **PodDisruptionBudget** (`podDisruptionBudget`) with `maxUnavailable: 1` for web and worker. The scheduler has none:
  it is one pod and a drain simply restarts it.
- **NetworkPolicy** (`networkPolicy.enabled`): only `networkPolicy.ingressFrom` peers reach port 7432. Egress is open by
  default because workers must reach every target database you register on arbitrary ports. With
  `networkPolicy.egress.restrict=true` list DNS (on by default), the application database, S3, the target databases and
  your chat/SIEM endpoints in `networkPolicy.egress.rules`.
  The ingress rule applies to every pod of the release, so a Prometheus outside `networkPolicy.ingressFrom` cannot
  scrape `/metrics`: add its namespace to `ingressFrom` when you enable `metrics.enabled`.
- **Security context**: non-root numeric user 82 (`www-data`), all capabilities dropped, no privilege escalation,
  `RuntimeDefault` seccomp, no service account token mounted.
- **`readOnlyRootFilesystem` is off.** The image's entrypoint writes `/var/www/html/.env`, `bootstrap/cache` and
  `storage/` at start; a read-only root needs an image change (`.env` and the `storage/` skeleton outside the image
  layers), not just emptyDir mounts. It is a follow-up for the image, not something the chart can promise today.

## S3 and Redis

- **S3 results (`results.disk=s3`)** use the `s3` disk of `config/filesystems.php`; the image carries the Flysystem S3
  adapter (`league/flysystem-aws-s3-v3`). The chart sets `QUERYPROXY_RESULT_DISK`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`,
  `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT` from `results.s3.*`, and the keys from the Secret. Without S3, use
  `results.disk=local` on a ReadWriteMany volume (`persistence.accessMode=ReadWriteMany`, `persistence.enabled=true`)
  shared by web and worker.
- **Redis (`redis.enabled`)** is optional; everything works on the database alone. The chart defaults to
  `redis.client: predis`, and the image carries `predis/predis` (phpredis would need a Dockerfile change). It sets
  `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, and `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` to `redis`
  (each switchable in `redis.useFor*`); `REDIS_PASSWORD` comes from the Secret. A Redis ACL user or another database
  go in `config.extraEnv` (`REDIS_USERNAME`, `REDIS_DB`, `REDIS_CACHE_DB`). TLS to a managed Redis (`rediss://`) works
  only through `REDIS_URL`, because `config/database.php` has no scheme setting; put that URL, which carries the
  password, in a Secret and reference it from `config.extraEnvFrom`.

## Values

| Key                                              | Default                 | Meaning                                                      |
| ------------------------------------------------ | ----------------------- | ------------------------------------------------------------ |
| `image.repository`, `image.tag`                  | `queryproxy/queryproxy`, appVersion | Image; pin a version                              |
| `config.appUrl`                                  | `http://localhost:7432` | Public URL; its host is the accepted and probed host         |
| `config.trustedProxies`, `config.trustedHosts`   | empty                   | `TRUSTED_PROXIES` (never `*`), extra hostnames               |
| `config.executionTimeout`                        | `300`                   | Seconds per query; drives worker timeouts                    |
| `config.workerProcesses`                         | `1`                     | `queue:work` processes per worker pod                        |
| `config.extraEnv`, `config.extraEnvFrom`         | empty                   | Any other variable from `.env.example`                       |
| `database.*`                                     | `sqlite`                | Connection, host, port, name, username, `sslMode`            |
| `redis.*`                                        | off                     | Optional Redis for cache, queue and sessions                 |
| `results.disk`, `results.s3.*`                   | `local`                 | Result file storage                                          |
| `persistence.*`                                  | enabled, 5Gi, RWO       | Volume at `storage/app` (SQLite)                             |
| `secrets.*`                                      | chart-managed           | `existingSecret`, `mountAsFiles`, key names, values          |
| `admin.email`, `admin.name`                      | empty                   | First-boot administrator (web pod)                           |
| `metrics.enabled`                                | `false`                 | Turn on `/metrics` with `secrets.metricsToken`               |
| `web.*`, `worker.*`, `scheduler.*`, `migration.*` | see file               | Replicas, resources, scheduling, autoscaling                 |
| `service.*`, `ingress.*`, `networkPolicy.*`      | see file                | Exposure and network policy                                  |

## Testing the chart

```bash
helm lint deploy/helm/queryproxy
helm lint deploy/helm/queryproxy -f deploy/helm/queryproxy/values-ha.yaml

# Needs docker, kind, kubectl, helm:
docker build -t queryproxy:helm-smoke .
kind create cluster --name queryproxy-smoke && kind load docker-image queryproxy:helm-smoke --name queryproxy-smoke
tests/Integration/helm/smoke.sh
```

CI runs the same in `.github/workflows/helm.yml`: lint on Helm 3 and 4, then the kind smoke test (install with the
migration hook, `/up` returns 200, a sample request is approved and completed by the worker pod, an upgrade keeps the
`APP_KEY`).
