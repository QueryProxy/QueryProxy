# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

## [0.2.1] — YYYY-MM-DD

### Security

- **`EXPLAIN ANALYZE <DML>` was classified as a read and ran the DML.**
  `EXPLAIN ANALYZE` executes the statement it explains, but the guard treated
  every `EXPLAIN` as a read, so `EXPLAIN ANALYZE DELETE FROM t` skipped the
  `WHERE` rule, the write classification and the approval flow. An `EXPLAIN`
  that analyzes is now judged as the statement inside it (type, denylist and
  `WHERE` rule); `EXPLAIN` without `ANALYZE` stays a read. The option list is
  checked against PostgreSQL's known options, and an option block the guard
  cannot read (quoted or unknown options, an unclosed list, an expression value)
  is rejected.
- **`SELECT … INTO <table>` created a table but was classified as a read.** It
  is now a write and DDL, and no `LIMIT` is added. `SELECT … INTO @var` stays a
  read; `INTO OUTFILE` / `INTO DUMPFILE` were already denied.
- **Functions with side effects passed as reads.** Calling `nextval`, `setval`,
  the advisory-lock family, `get_lock`, `release_lock`, `pg_sleep*`, `sleep` or
  `benchmark` now makes a statement a write. `pg_terminate_backend`,
  `pg_cancel_backend`, `pg_reload_conf`, `pg_rotate_logfile`, `pg_promote`,
  `pg_switch_wal`, `dblink*` and the server-side file functions
  (`pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`, `lo_import`, `lo_export`,
  `load_file`) are denied. `set_config` is a write, and is denied when its first
  argument is not a literal. Names are matched after quoting and `U&` escapes
  are resolved. `dblink*` is a prefix match, so a user-defined function that
  starts with `dblink` is denied too. The new
  `QUERYPROXY_EXTRA_BLOCKED_FUNCTIONS`,
  `QUERYPROXY_EXTRA_STATE_CHANGING_FUNCTIONS` and
  `QUERYPROXY_EXTRA_RESOURCE_CONSUMING_FUNCTIONS` settings extend these lists,
  and `QUERYPROXY_EXTRA_BLOCKED_PROCEDURES` (SQL Server, below) and
  `QUERYPROXY_EXTRA_SQLITE_PRAGMAS` (SQLite, below) do the same for theirs;
  they can only add to the built-in entries.
- **SQL Server and SQLite constructs that reach outside the database were not
  covered.** On SQL Server the guard now denies `xp_*`, `sp_configure`,
  `sp_oa*`, `sp_addlinkedserver`, `sp_addlinkedsrvlogin`, `sp_serveroption`,
  `sp_addextendedproc`, `sp_execute_external_script`, `OPENROWSET`,
  `OPENDATASOURCE`, `OPENQUERY` and `BULK INSERT`, and also denies `DENY`
  statements. A batch without semicolons is split at T-SQL statement boundaries
  and every part is checked, so `SELECT 1 DELETE FROM t` is rejected. On SQLite
  it denies `ATTACH`, `DETACH`, `VACUUM … INTO` and `load_extension(`, and only
  allows a built-in set of read-only `PRAGMA`s (`table_info`, `table_xinfo`,
  `index_list`, `index_info`, `index_xinfo`, `foreign_key_list`,
  `database_list`, `table_list`, `collation_list`, `function_list`,
  `compile_options`), which `QUERYPROXY_EXTRA_SQLITE_PRAGMAS` can extend (bare
  form only); any `PRAGMA` that sets a value, and any not on the list, is
  rejected. The connection form warns when `QUERYPROXY_SQLITE_ALLOWED_DIR` is
  empty.
- **Dynamic SQL was not inspected.** `EXEC('…')`, `sp_executesql`,
  `EXECUTE IMMEDIATE '…'`, MySQL `PREPARE … FROM '…'` and PostgreSQL
  `PREPARE … AS <statement>` are now checked together with the SQL they carry.
  When the SQL is a literal it is judged by the same rules as a top-level
  statement; when it is a variable, an expression, a concatenation or an
  `EXEC(…) AT <server>`, the request is rejected. **This can reject legitimate
  use**: code that builds SQL at runtime and passes it through these forms no
  longer runs through QueryProxy. **Behaviour change:** on SQL Server these 14
  system procedures are rejected whatever their arguments are: `sp_prepare`,
  `sp_prepexec`, `sp_prepexecrpc`, `sp_execute`, `sp_cursoropen`,
  `sp_cursorprepare`, `sp_cursorprepexec`, `sp_msforeachtable`,
  `sp_msforeachdb`, `sp_msforeach_worker`, `sp_sqlexec`, `sp_send_dbmail`,
  `sp_add_jobstep` and `sp_update_jobstep`. `sp_executesql` is not on the list;
  its SQL text is inspected. Dynamic SQL nested more than 3 levels deep is
  rejected.
- **`UPDATE` / `DELETE` inside a CTE skipped the `WHERE` rule.** The bodies of
  `WITH … AS (…)` and the statement that follows the `WITH` are now checked,
  including nested `WITH`s up to 3 levels; a `WITH` the guard cannot read is
  rejected. `INSERT` and `MERGE` bodies are not checked separately; the outer
  statement is a write.
- **SQL Server `TOP` was only half enforced.** A `SELECT` without `TOP` was not
  bounded by the guard, and `TOP` values with `PERCENT`, decimals or expressions
  were read as plain numbers. An existing `TOP n` / `TOP (n)` stays capped
  at 10000; now a top-level `SELECT` without `TOP` gets
  `TOP (<default row limit>)`, and `TOP … PERCENT`, expressions and decimals
  are rejected. For `WITH`, `OFFSET`, `UNION`, `EXCEPT` and `INTERSECT`
  no `TOP` is added; the executor's row cap applies.
- **New teams started with no masking rules.** A team created from now on starts
  with the default masking rules. Existing teams are not changed (see
  Upgrading).

### Fixed

- `retry_after` for the database, Beanstalkd and Redis queues no longer falls
  back to Laravel's 90 seconds, which is below the worker's 310-second timeout
  and let a slow query be re-reserved while it was still running and then marked
  failed (notably with `QUEUE_CONNECTION=redis`). It is now derived from
  `QUERYPROXY_EXECUTION_TIMEOUT` (timeout + 30 seconds, 330 by default)
  instead of a fixed number. An explicit `*_QUEUE_RETRY_AFTER` still wins; a
  value at or below the timeout logs a warning when a `queue:work` worker
  starts. The Docker entrypoint derives the worker `--timeout`
  (timeout + 10) and `stopwaitsecs` (timeout + 20) the same way.
- **A query that finished late could turn a Failed request into Completed.** The
  executor now moves a request to Completed only if it is still Running. A late
  result is discarded, the result file is removed, and an
  `execution.late_completion` audit entry is written; for a write it records
  that the write was committed.

### Upgrading

- **Remove the fixed `retry_after` lines from your `.env`.** The 0.2.0
  `.env.example` shipped `DB_QUEUE_RETRY_AFTER=330` as an active setting, and an
  explicit value always wins over the derived one. If that line is still in your
  `.env` (or `REDIS_QUEUE_RETRY_AFTER=330`, `BEANSTALKD_QUEUE_RETRY_AFTER`), delete
  it unless you set it on purpose; otherwise raising
  `QUERYPROXY_EXECUTION_TIMEOUT` leaves `retry_after` at 330, a long query is
  re-reserved while it still runs, and a write can commit while its request is
  marked Failed. A `--timeout` set by hand on a separately run worker (the
  Docker image derives it itself) should be the execution timeout + 10 seconds.
- **Existing teams do not get masking rules automatically.** A DBA opens the
  team's **Masking** page and runs **Add default rules**. Query Studio and the
  Masking page now show a banner for a team that has no masking rules, or none
  enabled.
- **Some statements now need approval.** `SELECT` statements that call
  `nextval`, `setval`, an advisory-lock function, `get_lock`, `release_lock`,
  `pg_sleep*`, `sleep`, `benchmark` or `set_config` are classified as writes, so
  they go through the approval flow.
- **Some statements that used to pass are now rejected.** In particular:
  `EXPLAIN ANALYZE` on a `DELETE` / `UPDATE` without `WHERE`, dynamic SQL built
  from a variable or an expression, the 14 SQL Server procedures listed under
  Security, SQL Server `TOP … PERCENT`, any `PRAGMA` outside the allowed set on
  SQLite, and, on PostgreSQL, any `UESCAPE` clause and any `EXPLAIN` option
  block the guard cannot read. `SELECT … INTO <table>` is now a write.
- **SQL Server `SELECT` without `TOP` now returns 1000 rows by default.** The
  previous ceiling was 10000.
- The full list is in the
  [SQL guards wiki page](https://github.com/QueryProxy/QueryProxy/wiki/SQL-guards).

## [0.2.0] — 2026-09-18

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
  log), and `CREATE FUNCTION … SONAME`, `pg_read_file()` and friends were not
  listed at all. The guard now judges the dangerous part rather than the
  keyword: `CREATE EXTENSION` and `DO` are refused for untrusted procedural
  languages, `SET GLOBAL` / `SET PERSIST` for file-path, logging and plugin
  variables, and a `DO` body is scanned for the unconditionally blocked
  statements — so `DO $$ BEGIN DROP DATABASE prod; END $$` does not get through.
  That scan is best-effort by nature: SQL a block assembles at run time
  (`EXECUTE format(…)`) cannot be read statically, and the DBA approval remains
  the real boundary.
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
- Removing a ChatOps integration is administrator-only, matching the bar for
  setting its signing secret — a DBA could otherwise delete an integration and
  then be unable to put it back. DBAs still untick **Enabled** to switch one off.
- `TRUSTED_HOSTS` accepts extra hostnames for installations served under more
  than one name, since `X-Forwarded-Host` is no longer honoured.
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

[Unreleased]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.0
[0.1.3]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.3
[0.1.2]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
