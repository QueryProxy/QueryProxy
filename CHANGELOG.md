# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

### Security

- **ChatOps approvals could be made in someone else's name.** The webhook HMAC
  proves only that the caller knows the signing secret, but the approver's
  identity was read straight out of the callback body — and any team DBA could
  overwrite the signing secret without knowing the old one. Together that let a
  DBA approve their own request as a colleague (or as an admin), defeating the
  four-eyes rule and writing that colleague's name into the audit trail. Signing
  secrets are now administrator-only and rotating one requires the current
  secret; every Approve / Reject control carries a single-use action token bound
  to one request; and the resolved approver must belong to the integration's team.
- **Every proxy was trusted (`trustProxies(at: '*')`).** Any client could forge
  `X-Forwarded-For` and get unlimited login and 2FA attempts (both throttles are
  keyed per IP) and write a false client IP into the audit log, while a forged
  `X-Forwarded-Host` rewrote the link in password-reset mail. Trust is now opt-in
  through `TRUSTED_PROXIES`, `X-Forwarded-Host` is no longer honoured, and
  `trustHosts` pins the request host to `APP_URL`.
- **Content masking rules silently skipped non-string values.** PDO returns
  native integers for `BIGINT`/`DECIMAL`, so a numeric column that matched no
  column pattern — a card number or a national id stored as `BIGINT` — reached
  the result store unmasked. Content rules now see every scalar.
- **The SQL guard's denylist had bypasses.** `SET @@GLOBAL.…` walked straight
  past the `SET GLOBAL` rule (arbitrary file write through the MySQL general
  log), and `CREATE FUNCTION … SONAME`, `CREATE EXTENSION`, `DO`, `pg_read_file()`
  and friends were not listed at all. All are blocked now.
- **TOTP codes could be replayed** for the ~90 seconds their window stayed open;
  an accepted code is now burned via `two_factor_last_used_timestamp`.
- **Chat webhook URLs leaked into the logs.** A connection error carried the full
  URL — and for Slack the secret *is* the URL path — into `laravel.log`.
- **CSV exports did not guard the header row** against spreadsheet formula
  injection, although the data rows did; column names come from the target schema
  or the requester's own aliases.
- **Query results could be published without authorization** if the operator
  pointed `QUERYPROXY_RESULT_DISK` at a public disk: result paths are guessable,
  so `storage:link` exposed every team's rows. Such a disk is now refused.
- Default masking rules gained content patterns for vendor API keys, JWTs,
  PEM private keys and high-entropy secrets, so a secret hidden behind a column
  alias (`SELECT api_key AS k`) is still masked. The credit-card content rule was
  narrowed to real IIN ranges so it no longer fires on epoch timestamps.
- Third-party GitHub Actions are pinned to commit SHAs, and the release workflow
  scans the image before any registry credential is on the runner.

### Changed

- **Breaking — set `TRUSTED_PROXIES` when running behind a reverse proxy.** It is
  empty by default. Until it names your proxy, `X-Forwarded-Proto` is ignored, so
  HTTPS is not detected and audit entries record the proxy's address rather than
  the client's. Never use `*`.
- **Breaking — a system administrator must set the ChatOps signing secret.** DBAs
  keep control of the webhook URL and the enabled flag, but can no longer enter or
  rotate the secret. Existing installations should rotate it once after upgrading:
  any DBA who configured ChatOps already knows the current value.
- **Breaking — Approve / Reject buttons in chat messages posted before this
  version stop working**, because they carry no action token. Decide those
  requests in the web UI. Teams automations must start sending the `token` field
  from the card's `queryproxy` envelope.
- A TOTP code is now single-use, so signing in on a second device within the same
  30-second window requires waiting for the next code.
- New content masking rules apply to new teams only. Run **Masking → Add
  defaults** per team to pick them up; it is idempotent and leaves existing rules
  untouched.

## [0.1.3] — 2026-09-08

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

[0.1.3]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.3
[0.1.2]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
