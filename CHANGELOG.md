# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

### Changed

- Image bases moved to `php:8.5-fpm-alpine` and `node:26-alpine`. OPcache is no
  longer installed explicitly: PHP 8.5 links it statically and enables it by
  default, and `docker-php-ext-install opcache` fails there.
- Release workflow actions updated to `docker/login-action@v4`,
  `setup-buildx-action@v4`, `setup-qemu-action@v4` and `metadata-action@v6`.

## [0.1.2] — 2026-09-08

### Changed

- **One-container install.** The image now runs the queue worker and the
  scheduler alongside the web tier, so `docker run -p 7432:7432 -v
  queryproxy-data:/var/www/html/storage/app queryproxy/queryproxy` is a
  complete instance. `QUERYPROXY_RUN_WORKER` / `QUERYPROXY_RUN_SCHEDULER` turn
  them off for split deployments, and `QUERYPROXY_WORKER_PROCESSES` runs more
  than one worker. `docker-compose.yml` collapsed to that single service.
- **One volume.** The SQLite database and the generated `APP_KEY` moved next to
  the result files under `/var/www/html/storage/app`. Volumes from earlier
  versions keep working: an existing `/var/www/html/database/database.sqlite`
  (or `.app_key`) is still used when present.
- Releases publish to Docker Hub (`queryproxy/queryproxy`) as well as GHCR.

### Added

- `php artisan queryproxy:create-admin` creates the first administrator, and
  `QUERYPROXY_ADMIN_EMAIL` / `QUERYPROXY_ADMIN_PASSWORD` / `QUERYPROXY_ADMIN_NAME`
  create it on first boot — a fresh instance no longer needs demo seeding to
  have an account to log in with.
- Container `HEALTHCHECK` against `/up`, so `docker run` reports health without
  a compose file.

## [0.1.1] — 2026-09-08

### Fixed

- Demo seeding (`QUERYPROXY_SEED_DEMO=true`) crashed the production container
  on first boot: the seeder relied on Faker, which is a dev-only dependency and
  absent from `--no-dev` installs. The seeder no longer uses factories.

### Added

- `.dockerignore`, so local `docker build` uses the same clean context as CI
  instead of copying host `vendor/`, `node_modules/` and `.env` into the image.

## [0.1.0] — 2026-09-08

The first public release.

### Added

- **Teams & RBAC** — Admin / DBA / Developer / Auditor roles, hard team
  isolation, per-developer connection grants.
- **Connection vault** — PostgreSQL, MySQL, MariaDB, SQL Server and SQLite
  targets with AES-256-encrypted credentials and a connection tester.
- **Query Studio** — CodeMirror editor with server-side AST guards:
  WHERE-less `UPDATE`/`DELETE` rejected, `LIMIT` injection (default 1000) and
  clamping (cap 10000), explicit-transaction rule for multi-statement
  submissions, administrative statements blocked.
- **Approval workflow** — pending queue, mandatory rejection reasons,
  self-approval prevention with audited admin override, requester
  cancellation, in-app notifications.
- **ChatOps** — interactive Slack approvals (HMAC-SHA256 `v0` signatures,
  ±5-minute replay window, member-ID identity mapping) and a Teams
  HMAC-verified action endpoint plus channel announcements.
- **Async execution** — database-backed queue worker, cursor streaming to
  NDJSON result files with constant memory, atomic multi-statement
  transactions with rollback on failure.
- **Dynamic data masking** — column-pattern and content-regex rules with
  full / partial / hash strategies, applied at write time; default rule set
  and a test playground.
- **Results** — paginated viewer, streamed CSV export, configurable retention
  with automatic pruning.
- **Immutable audit log** — every security-relevant event, filterable auditor
  UI, CSV export.
- **Password management** — profile page (name + password change),
  enumeration-safe e-mail reset flow, admin password reset.
- **Deployment** — zero-config `docker compose up` (app + worker + scheduler,
  SQLite default), published container image `ghcr.io/queryproxy/queryproxy`.

[0.1.2]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
