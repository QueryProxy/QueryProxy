# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

## [0.3.0] - 2026-10-25

Step-by-step upgrade and rollback instructions, with the migration list, are
on the [upgrading page](https://queryproxy.com/docs/upgrading/). Six changes
below are marked **Breaking** under Changed: the redacted chat SQL preview,
soft-deleted users with required override reasons, required TLS on new
connections, the Teams Workflows card format, and two that need action
before you upgrade: the database privileges the audit migration needs, and
the pinned time zone of MySQL and MariaDB application connections. If the
migration user lacks those privileges the upgrade stops and the container
does not start.

### Security (0.3.0)

- **Reads run in a read-only session on the target database.** PostgreSQL,
  MySQL, MariaDB and SQLite enforce it in the engine. SQL Server has no such
  session: use the optional read-only identity (below) with `SELECT`
  privileges only. The connection form warns about this.
- **MySQL and MariaDB reads could leave their read-only transaction.** MySQL
  commits implicitly before DDL, which ended the transaction opened with
  `SET TRANSACTION READ ONLY` and let the statement run outside it with write
  access. The SQL guard classes DDL as a write, so reaching this needed a
  statement the guard had misclassified as a read and a DBA who approved it
  as one. It was found by the integration tests against real databases
  before this release and was never in a released version: read-only
  sessions are new in 0.3.0. Reads on MySQL and MariaDB now set the session
  itself read only, so a statement that ends the transaction is still
  refused, and if the transaction is gone when a read commits the run fails
  instead of reporting success. The read path also classifies the statement
  again and refuses a write sent as a read, recording `refused_by: guard` in
  the audit log. The SQL guard and the DBA approval are unchanged; this
  closes a gap in the database-side layer.
- **SQL literals are redacted in chat, audit and listings.** String and
  numeric literals become placeholders in Slack and Teams previews, audit
  metadata and its export, the dashboard and the request lists. The raw SQL
  stays on the request page for the requester, the team's `dba` users and
  the admin. An Auditor sees redacted SQL only. When a statement cannot be
  redacted safely the preview is hidden.
- **New connections require TLS** (see Changed). Existing connections keep
  their behaviour and are flagged until you enforce TLS on them.
- **Release images are signed** with cosign (keyless) and carry an SBOM and a
  SLSA provenance attestation; an OpenSSF Scorecard workflow runs weekly.
  How to verify is in [SECURITY.md](SECURITY.md#verifying-release-images).

### Added (0.3.0)

- **Audit hash chain.** Each audit entry stores the hash of the previous one
  and its own hash, so edits, deletions and reordering are detectable.
  `php artisan audit:verify` walks the chain from the first record and
  reports the first break; `audit:checkpoint` (scheduled daily) records the
  latest chain hash as an audit event. Database triggers reject `UPDATE` and
  `DELETE` on `audit_logs` on every supported driver (and `TRUNCATE` on
  PostgreSQL; MySQL and MariaDB triggers cannot block it).
- **Audit export to external systems.** Opt-in sinks, set with
  `QUERYPROXY_SIEM_SINKS`: JSON lines on stdout, RFC 5424 syslog over
  udp, tcp or tls, and an HMAC-signed HTTPS webhook. Delivery is queued after
  the audit record commits, retried, and dead-lettered to `failed_jobs`, so a
  down sink never blocks the audit write. Events follow
  `docs/audit-event.schema.json` and carry no per-event chain hashes.
- **Prometheus metrics on `/metrics`**: counters for requests, approvals,
  executions (completed, failed, cancelled, deferred for a busy connection),
  guard rejections by rule and masked columns. Off by default (`404`); it
  turns on when `QUERYPROXY_METRICS_TOKEN` or
  `QUERYPROXY_METRICS_ALLOWED_IPS` is set. A scrape passes with the Bearer
  token or from an allowed address, so an address on the allowlist needs no
  token; anything else gets `401`. Labels never carry SQL, user names or
  hosts. A Grafana dashboard ships as `docs/grafana-queryproxy.json`.
- **`php artisan queryproxy:doctor`** checks an installation without changing
  it: configuration and `APP_KEY`, the database, queue worker and scheduler
  heartbeat, storage, TLS enforcement on connections and their PEM files,
  masking coverage, the audit chain (`--full-audit` verifies all of it), SIEM
  sinks and metrics. `--json` prints a machine-readable report and `--strict`
  also fails on warnings.
- **Secrets from files.** `APP_KEY`, `APP_PREVIOUS_KEYS`, `DB_PASSWORD`,
  `MAIL_PASSWORD`, `REDIS_PASSWORD`, `SLACK_BOT_USER_OAUTH_TOKEN`,
  `QUERYPROXY_ADMIN_PASSWORD`, `QUERYPROXY_METRICS_TOKEN` and
  `QUERYPROXY_SIEM_WEBHOOK_SECRET` can be given as the same name with a
  `_FILE` suffix, by the application config and by the Docker entrypoint.
  Setting both `NAME` and `NAME_FILE` is an error and the container does not
  start; a plain value of `null`, `(null)` or `(empty)` counts as unset.
- **Stop a running query** from the request page. The requester, an approver
  or an admin can stop it: `pg_cancel_backend` on PostgreSQL, `KILL QUERY` on
  MySQL and MariaDB, `KILL` on SQL Server, sent from a separate connection. On
  PostgreSQL and SQL Server the signal first checks the session's start time;
  MySQL and MariaDB connection ids are not reused. Not available on SQLite. A
  stopped run is rolled back, ends as `cancelled`, is audited as
  `execution.cancelled`, and notifies the requester when someone else stopped
  it. On SQL Server the target connection's user needs `VIEW SERVER STATE` and
  `ALTER ANY CONNECTION`; without them the request is still marked `cancelled`
  and a warning is shown, but the query runs on until its statement timeout.
  `KILL` ends the whole session there, where the other drivers cancel only the
  statement.
- **`hmac` masking strategy and checksum validators.** `hmac` pseudonymises
  values with a 256-bit key per team (stored encrypted), so analysts can join
  and count on masked columns. `queryproxy:masking:rotate-key` replaces the
  key; old and new pseudonyms then stop matching. A `validator` match type
  adds TCKN, VKN, TR IBAN and Luhn checks, and a Turkey PII rule pack
  (national id, tax id, IBAN, mobile and E.164 phone numbers, vehicle plates)
  can be installed per team. The VKN content validator ships disabled
  because its check digit matches too many unrelated numbers; the column rule
  still covers `vkn` and `tax_no` columns.
- **Sensitive-filter badge.** A request that filters, joins or sorts on a
  column covered by a masking rule is flagged in the approval list and on the
  request page, because the result can leak masked values through the
  predicate.
- **Connection TLS settings and a read-only identity.** Each connection has a
  TLS mode and an optional CA, client certificate and key (PEM material is
  stored encrypted and written to `storage/app/tls/` with owner-only
  permissions). A connection can also hold an optional, encrypted read-only
  identity that read requests use.
- **Suspending users.** Administrators suspend and resume users on the Users
  screen.
- **Pending-request reminders.** `requests:remind` reminds the team's DBAs
  and admins once, `QUERYPROXY_PENDING_REMIND_AFTER` hours (default `4`, `0`
  turns it off) after a request is filed, and re-posts chat cards with a
  fresh approval token once the old tokens have expired. Requests never
  expire.
- **Connection slots.** `QUERYPROXY_CONNECTION_SLOTS` (default `2`) limits
  how many requests run against one connection at a time. A request that
  finds every slot busy is re-queued after
  `QUERYPROXY_CONNECTION_SLOT_RETRY_DELAY` seconds (default `5`), audited as
  `request.execution_deferred` and shown with a "Waiting for slot" badge.
- **Integration tests on real databases.** A workflow runs the integration
  and live suites against PostgreSQL, MySQL, MariaDB and SQL Server, with and
  without TLS.

### Changed (0.3.0)

- **Breaking — `QUERYPROXY_CHAT_INCLUDE_SQL` now defaults to
  `literals_redacted`.** The Slack and Teams preview replaces string and
  number literals with `?`. Values: `literals_redacted` (default), `full` (the
  earlier behaviour) and `none`; `true` still means `full` and `false` means
  `none`. The audit screen and the audit CSV no longer carry raw SQL for any
  role, and the CSV column `sql` is now `sql_redacted`. Update scripts that
  read it by name.
- **Breaking — users are soft-deleted and overrides need a reason.**
  `query_requests.user_id` and `reviewed_by` become `RESTRICT`, so request
  history cannot disappear with a user, and code that hard-deletes users now
  fails on the foreign key. Deleting a user blocks their sign-in, sessions and
  chat identities and cancels their `pending` requests (audited as
  `request.cancelled`, actor `system`); approved and queued requests are not
  touched, and the e-mail address stays reserved. A suspended user is blocked
  the same way but keeps their pending requests. Every admin override
  approval now needs a written reason on every channel, stored in the audit
  log: a Slack **Approve** press that would be an override is not applied and
  answers with a link to a web form, and the Teams action endpoint answers
  `422` to an override without `reason`. Rolling the user migration back is
  refused while any deleted or suspended account exists.
- **Breaking — new connections require TLS.** PostgreSQL uses
  `sslmode=require`, which encrypts but does not verify the server (use
  `verify-full` against a man-in-the-middle); MySQL and MariaDB require TLS
  without verifying the server certificate unless you tick "Verify the server
  certificate" and add a CA; SQL Server uses `Encrypt=true` and
  `TrustServerCertificate=false` (`pdo_sqlsrv` must be installed), so a
  self-signed or privately issued certificate fails until its CA is trusted or
  `TrustServerCertificate` is turned on for that connection. SQLite has no
  TLS. Existing connections keep their old behaviour and are flagged in the
  connection list: PostgreSQL shows "TLS not enforced" (`sslmode=prefer`), the
  others "TLS: driver default". Edit each connection to enforce TLS;
  `queryproxy:doctor` lists those that do not. On SQL Server the enforcement
  is the ODBC driver's documented behaviour, not something QueryProxy
  implements, and the tests cannot simulate a server that offers no TLS; treat
  the network path as untrusted until you have checked it.
- **Breaking for custom flows — Teams Workflows URLs receive an Adaptive
  Card.** Webhook URLs on `*.powerplatform.com` and `*.logic.azure.com` now
  receive an Adaptive Card 1.5 instead of a MessageCard, and
  `*.powerplatform.com` joins the default webhook host allowlist. The
  `queryproxy` envelope with the action token stays at the top level, so the
  approval flow keeps working. A Power Automate flow that reads the
  MessageCard fields (`title`, `summary`, `sections[0].facts`) now reads them
  empty: read the card from `attachments[0].content`, or forward the body
  unchanged. Legacy connector URLs (`*.webhook.office.com`) still receive a
  MessageCard (see Deprecated).
- **Reads run in a read-only session with timeouts derived from
  `QUERYPROXY_EXECUTION_TIMEOUT`.** The statement timeout is the execution
  timeout minus 5 seconds (at least 1) and the lock timeout is
  `QUERYPROXY_LOCK_TIMEOUT` (default `5`); writes get the same limits. On
  MySQL the statement timeout applies to `SELECT` only. On PostgreSQL,
  `SELECT … FOR UPDATE` and `FOR SHARE` are rejected inside a read request.
  Once a statement has started, QueryProxy no longer reconnects to the target
  after a network drop: a brief drop fails the run instead of re-running it on
  a new session.
- **Results are streamed.** Rows are read through a server-side cursor on
  PostgreSQL and an unbuffered result on MySQL and MariaDB, so memory no
  longer grows with the result size.
- **Slots can make requests wait.** If you ran more workers against one
  connection than `QUERYPROXY_CONNECTION_SLOTS` allows, requests now stay
  `queued` where they used to run at once. `0` switches the limit off.
- **Breaking, check before upgrading — MySQL and MariaDB: the application
  connection time zone is pinned to UTC.** `config/database.php` sets
  `'timezone' => '+00:00'` on the `mysql` and `mariadb` connections.
  `TIMESTAMP` columns are stored as UTC and shown in the session time zone,
  which until now was the database server's. On a server that is not on UTC,
  every existing `TIMESTAMP` value (`created_at`, `updated_at` and the other
  `*_at` columns) is shown shifted by the server's UTC offset after the
  upgrade: a server at UTC+3 shows values 3 hours earlier, one at UTC-5 shows
  them 5 hours later (with a named zone such as `Europe/Berlin` the shift
  follows daylight saving). `DATETIME` columns and servers already on UTC are
  not affected. Open chat approval tokens (`chat_approval_tokens.expires_at`,
  24-hour lifetime) expire early by that offset on UTC+ servers, and a token
  with less time left becomes invalid at once; on UTC- servers they live that
  much longer. Delete the open tokens before upgrading to avoid either effect;
  the affected requests then get a fresh chat card from the reminder: `DELETE
  FROM chat_approval_tokens WHERE used_at IS NULL;`. Check the server with
  `SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone,
  TIMEDIFF(NOW(), UTC_TIMESTAMP());` first. The audit hash chain depends on
  the pin: `audit_logs.created_at` is hashed as UTC wall time and must read
  back unchanged.
- **Breaking, before upgrading — the audit migration needs trigger
  privileges.** The audit chain migration creates triggers. If it fails, the
  container does not start, because the entrypoint runs `migrate` on every
  start. The migration user needs the `TRIGGER` privilege on MySQL and MariaDB
  (with binary logging on, also `SUPER` or
  `log_bin_trust_function_creators=1`, otherwise it fails with error 1419) and
  ownership of the table on PostgreSQL. Existing audit records are chained
  from the first one during the migration, which can be run again after a
  partial failure. After the migration, `audit_logs` rows can no longer be
  updated or deleted.
- **The first reminder run can post many cards at once.** Pending requests
  older than 24 hours with no live approval token, in teams with an enabled
  chat integration, get a renewed chat card on the first `requests:remind`
  run after the upgrade, so a channel with a backlog sees a one-time burst.
- **The scheduler gains three jobs**: a daily `audit:checkpoint`,
  `requests:remind` every 15 minutes and a one-minute heartbeat written to
  the cache (read by `queryproxy:doctor`). Keep the scheduler running.
- **Masking.** The `hash` strategy is deprecated (see Deprecated). The
  built-in card-number regex is replaced by a Luhn validator rule in new
  default sets; existing teams keep the old rule and "Add default rules"
  adds the Luhn rule next to it. The TCKN validator no longer treats a digit
  run glued to a letter or an underscore as a candidate; a column named
  `tckn` is still caught by its column rule.
- As before, the Docker entrypoint sets the worker timeout to
  `QUERYPROXY_EXECUTION_TIMEOUT` plus 10 seconds and ignores
  `QUERYPROXY_WORKER_TIMEOUT`; `queryproxy:doctor` reports that value.

### Deprecated (0.3.0)

- **The `hash` masking strategy** stays selectable and is labelled
  deprecated in the UI; use `hmac`.
- **MessageCard payloads to Teams legacy connector URLs.** Microsoft's
  [retirement notice](https://devblogs.microsoft.com/microsoft365dev/retirement-of-office-365-connectors-within-microsoft-teams/)
  (April 14, 2026 update) says the rollout ran May 18 to May 22, 2026, after
  which Office 365 Connectors no longer function, so a legacy URL may already
  have stopped working. Move to a Workflows URL.

### Fixed (0.3.0)

- A job that fails now cancels its query on the target database instead of
  leaving it running, and reads cut off at the row cap on MySQL and MariaDB
  send `KILL QUERY`.

## [0.2.7] - 2026-10-18

### Security (0.2.7)

- **`EXPLAIN`, `KILL` and `WITH` bodies are parsed with bounded memory.** The
  inner statement behind these keywords was parsed by a parser that kept every
  error with a full backtrace, so a 63 KB dotted-name chain behind `EXPLAIN`
  peaked at about 300 MB and could exhaust a 256 MB `memory_limit`. Inner
  bodies now keep only the first error and stay well under 64 MB on the same
  input.
- **`UPDATE` and `DELETE` statements the guard cannot parse are refused.**
  When such a statement could not be parsed, the guard fell back to a text
  search for `WHERE`, which also matched the `WHERE` of a subquery and let
  some statements without their own `WHERE` clause through. They are now
  refused as too complex to verify.

### Fixed (0.2.7)

- Nested `DO` blocks no longer repeat the same refusal for one language
  literal; independent violations keep their own messages. Which statements
  are accepted or refused is unchanged.
- The test suite no longer needs a generated `APP_KEY`: `phpunit.xml` sets a
  fixed, non-secret test key.

### Changed (0.2.7)

- **`QUERYPROXY_EXECUTION_TIMEOUT` is capped at 86400 seconds.** A valid value
  above 86400 is clamped to 86400 instead of saturating in the application or
  overflowing the image entrypoint's arithmetic. The entrypoint prints a
  warning at container start when it clamps; the application clamps silently.
- Inspecting a large `DO` body is faster (a 63 KB body takes about 29% less
  time), because lexing results are shared instead of repeated.

## [0.2.6] - 2026-10-18

### Fixed (0.2.6)

- **`QUERYPROXY_EXECUTION_TIMEOUT` is read by one rule everywhere.** The
  application config, the queue's `retry_after` and the image entrypoint now
  all accept only a whole number of seconds greater than zero and fall back to
  300 for anything else (text, empty, `0`, `000`, negative, decimal, a value
  with a unit, `true`). Before, the entrypoint took `0` and `000` literally
  and derived a 10-second worker timeout from them, and a value such as
  `true` was read as 1 second by the config. `retry_after` for an invalid
  value is 330, as for the default. The config falls back silently; the
  entrypoint still warns at container start.
- **The `sql_mode` refusal pointed at the wrong cause.** The message told you
  to remove `NO_BACKSLASH_ESCAPES` / `ANSI_QUOTES` from the server's or the
  user's default `sql_mode`, but QueryProxy overrides the session's mode when
  it connects, so a default cannot be the source. It now says the flag was set
  after connecting (for example by a proxy or connection pooler that rewrites
  session variables) and to look there.
- `DO LANGUAGE E'SQL' …` was refused with two messages for the same problem;
  it now gets one. The refusal itself is unchanged.

### Security (0.2.6)

Tightening of the SQL guard. Unless noted, every item below is a refusal at
submission, with the same explicit message as the existing rules. The guard
remains a layer of defence in depth; the DBA approval is still what authorizes
a statement.

- **Role and user switches are refused.** `SET [SESSION|LOCAL] ROLE`,
  `RESET ROLE` (also written `RESET "role"`), `SET … SESSION AUTHORIZATION`,
  `RESET SESSION AUTHORIZATION`, MySQL and MariaDB `SET ROLE` /
  `SET DEFAULT ROLE`, and `set_config('role', …)` /
  `set_config('session_authorization', …)`. `role` and
  `session_authorization` join the built-in list of guarded settings, which
  `QUERYPROXY_EXTRA_DANGEROUS_VARIABLES` can only extend.
- **`ALTER SYSTEM` is refused** on PostgreSQL, whatever it sets.
- **Writes to the system catalog are refused.** PostgreSQL: `INSERT`,
  `UPDATE`, `DELETE` and `MERGE` into `pg_catalog`, `pg_toast` and `pg_…`
  tables. MySQL and MariaDB: the same statements and `REPLACE` against the
  `mysql` schema. Qualified, quoted and CTE-wrapped spellings are covered.
  On MySQL and MariaDB `USE mysql` is refused as well (also inside `PREPARE`,
  `EXECUTE IMMEDIATE` and `SET STATEMENT … FOR`), so that a name without a
  schema cannot be resolved against the catalog. Reading the catalog
  (`SELECT * FROM pg_proc`, `SELECT … FROM mysql.user`) still passes.
- **`READ WRITE` is refused** in `SET TRANSACTION`, `SET SESSION
  CHARACTERISTICS`, `BEGIN` and `START TRANSACTION`, so a submission cannot
  lift a read-only transaction. `READ ONLY` still passes.
- **Writes to the MySQL / MariaDB read-only switch are refused** in every
  scope and spelling (`transaction_read_only`, `tx_read_only`, `SESSION`,
  `GLOBAL`, `@@…`, `SET STATEMENT … FOR`).
- **A MySQL / MariaDB write whose default database is `mysql` is refused at
  execution.** A table name without a schema would otherwise resolve inside
  the system schema. The executor reads `DATABASE()` on the connection before
  the first statement; a single-statement read and a connection with no default
  database are not affected.
- **`ALTER DATABASE|ROLE|USER … RESET` of a guarded setting (or `ALL`) is
  refused** on PostgreSQL, and the action of a `CREATE [OR REPLACE] RULE`
  (`DO [ALSO|INSTEAD] …`) is now inspected by the guard's own rules instead of
  being skipped.
- **SQL Server impersonation is refused:** `EXECUTE AS`, `EXEC AS`, `SETUSER`
  and `REVERT`, including inside `EXEC('…')` and `sp_executesql`, and
  `WITH EXECUTE AS` in object definitions. A column named `execute_as` and the
  text inside a string literal are not affected.
- **A very long dotted-name chain could stall a worker.** The guard re-walked
  the rest of the chain at every part, so the cost grew with the square of the
  chain length and a statement near the editor's size limit could keep a PHP
  process busy for a long time. It now scans in linear time. No refusal was
  added or relaxed by this change.
- The new rules apply inside `DO` and routine bodies, `PREPARE` and
  `EXECUTE IMMEDIATE` text, CTE bodies, `EXPLAIN ANALYZE`, MariaDB `ANALYZE`
  and `SET STATEMENT … FOR`.

## [0.2.5] - 2026-10-10

### Added (0.2.5)

- `php artisan queryproxy:mask-error-messages [--dry-run]` masks the error
  messages already stored on query requests and the copy of them inside in-app
  "request failed" notifications, using each connection's current rules (a
  request or connection that no longer exists gets the fixed message). Safe to
  run repeatedly; the immutable audit log is left untouched and the run is
  recorded as one `error_messages.masked` entry (scanned, changed, replaced by
  the fixed message, skipped; and scanned, changed, replaced by the fixed
  message and skipped for notifications) even when it stops halfway. A record
  whose connection rules cannot be read is skipped, left unchanged and reported,
  and the command exits non-zero.
- **DDL flag on requests.** A request that contains DDL now carries a `DDL`
  badge in the approvals queue and on the request page, and the Slack and
  Teams "new request" messages say so, so the approving DBA sees it before
  deciding. The flag was already computed and stored; it was never shown.

### Fixed (0.2.5)

- **A request failed by the job's `failed()` handler left no trace.** It now
  writes the `request.execution_failed` audit entry (with
  `source: job_failed`) and notifies the requester, as a failure inside the
  executor already did. A request that has already completed is still never
  flipped.
- The Teams HMAC docblock described the key as `base64_decoded_secret`; it now
  states what the code does: the secret base64-decoded in strict mode, or the
  raw secret when it is not valid base64.
- **Auditors could not reach the Requests list from the menu.** The sidebar
  hid the link for the auditor role although the page, the route and the
  policy already allowed it. Auditors now see the team's requests from the
  menu; result data stays closed to them.
- The Slack and Teams "account not linked" replies, the chat-ops setup
  steps and the add-member error pointed to "Admin → Users"; they now name
  the actual screen, Teams & Users → Manage users.

### Security (0.2.5)

- **Error messages of failed queries are masked like result data.** Driver
  errors echo the offending values (`Duplicate entry 'bob@x.io'`,
  `Key (phone)=(...)`, the SQL with its bindings), and the message was stored
  and shown unmasked in the request, the audit log and the in-app
  notification. It now goes through the connection's masking rules first:
  content rules mask matches in place, and when the message names a column
  covered by a column rule every literal value in it is masked. The raw
  message stays in the application log only. This closes the gap in ADR-014
  for new failures. It does not rewrite history: audit entries written by
  earlier versions (`request.execution_failed`, `execution.late_completion`)
  keep the raw text, because the audit log is immutable. Run
  `php artisan queryproxy:mask-error-messages` to mask the messages stored on
  query requests and in the in-app "request failed" notifications by earlier
  versions.
- **The job `failed()` safety net now redacts and masks too.** It stored the
  raw exception message; it now uses the same redact-and-mask path as the
  executor.

## [0.2.4] - 2026-10-04

### Fixed (0.2.4)

- The live PostgreSQL test that lifts a read-only transaction failed when
  `pdo_pgsql` was built against libpq older than 17: the driver frees prepared
  statements with `DEALLOCATE`, which takes a snapshot and makes PostgreSQL
  refuse the switch back to read-write. The test's setup statements now run
  unprepared. The guard itself is unchanged.

### Security (0.2.4)

- **`league/commonmark` updated to 2.10.3.** 2.10.0 is affected by
  GHSA-3q6v-r5mr-hxv8 (high) and GHSA-97jj-33gv-5xf9 (medium). The release
  image scan failed on them, so the container images for 0.2.1, 0.2.2 and
  0.2.3 were never published; 0.2.4 is the first image after 0.2.0 and
  carries all of their changes.

## [0.2.3] - 2026-10-04

### Changed (0.2.3)

- **CI and the live test setup.** `ci.yml` runs with a read-only `contents`
  token and checks out without persisting credentials, as `live-pgsql.yml`
  already did. PostgreSQL for the `live-pgsql` group (the workflow's service
  and `docker-compose.test.yml`) is initialised with `--auth-host=scram-sha-256`,
  because the image trusts loopback otherwise and the `dblink_exec` test never
  sent the password it is meant to prove it passes. The `dblink_exec` test now
  passes `host`, `port` and `password` in an escaped connection string
  (`QUERYPROXY_LIVE_PGSQL_SERVER_HOST` / `_SERVER_PORT` override the address the
  server sees itself at). The compose file listens on `55432` inside the
  container as well, so the documented command no longer needs
  `QUERYPROXY_LIVE_PGSQL_SERVER_PORT`, and keeps its data directory on tmpfs so
  every container starts from a fresh `initdb`. **If you run the compose
  example locally, recreate the container once**
  (`docker compose -f docker-compose.test.yml down`, then `up -d --wait`).

### Fixed (0.2.3)

- **A write refused by a read-only session was silently run again.** Laravel
  counts PostgreSQL SQLSTATE `25006` and MySQL/MariaDB's `--read-only` error as
  a lost connection, so outside a transaction it reconnected and re-ran the
  write on a fresh, writable session. The lost-connection detector now reports
  read-only errors as not lost and defers everything else to the framework's
  detector. **Trade-off:** after a MySQL/Aurora failover that leaves a
  connection on a read-only node, or on a PostgreSQL hot standby, the request
  fails with the database's error instead of being retried on a new connection;
  a queue worker that lands there no longer stops itself on `25006` either, so
  restart workers after such a failover.
- **Large SQL took seconds and hundreds of megabytes to inspect.** The guard
  lexed the same SQL several times per inspection, kept lexer errors with their
  backtraces and re-scanned its lexical ranges linearly. Each SQL text is now
  lexed once per inspection, dialect ranges are kept sorted and binary-searched,
  and the parser no longer leaves a cycle of garbage behind each request. On
  the worst 64 KiB SQLite input that was tried (`[a],` repeated) inspection
  went from 15.9 s and 589M of peak memory to 0.69 s and 21.5M, measured on a
  development machine under load; the verdicts do not change. New tests pin
  the lex count, the memory peak and a time bound relative to a bare lexer pass.

### Security (0.2.3)

- **PostgreSQL `DO` bodies were read with the MySQL lexer.** The guard scanned
  a `DO` body for blocked functions with MySQL's string rules, so a body such
  as `PERFORM 'a\', pg_read_file(…) --` hid the call inside what the lexer took
  for a string, while PostgreSQL ran it. Block and routine bodies (`DO`,
  `CREATE FUNCTION`, `CREATE PROCEDURE`) are now read with PostgreSQL's lexical
  rules (comments, `'…'`, `E'…'`, `U&'…'`, quoted identifiers, nested
  dollar-quoted strings) and go through the same blocked-function and
  guarded-setting rules as top-level SQL. **Behaviour change:** the following
  are now rejected.
  - Dynamic SQL (`EXECUTE`) inside a `DO` body or a function or procedure body,
    trigger functions included: the statement it runs is built at run time and
    cannot be inspected. Trigger `EXECUTE FUNCTION|PROCEDURE` clauses are not
    affected.
  - Any language other than `plpgsql` and `sql` for `DO`, `CREATE FUNCTION` and
    `CREATE PROCEDURE`. A quoted language name keeps its case, so
    `LANGUAGE "PLPGSQL"` is refused. `CREATE LANGUAGE` and
    `ALTER LANGUAGE … RENAME` are refused too.
  - `BEGIN ATOMIC` bodies, and `CREATE FUNCTION` / `CREATE PROCEDURE` without
    a body.
  - `query_to_xml*`, `ts_stat`, `ts_rewrite`, `crosstab*` and `connectby`
    join the blocked functions: they run SQL passed to them as a string.
  - A `SET` clause on a routine (`CREATE/ALTER FUNCTION|PROCEDURE … SET`), a
    database (`ALTER DATABASE … SET`) or a role (`ALTER ROLE|USER … SET`) now
    goes through the guarded-setting rule; one whose setting cannot be resolved
    is rejected.
- **`sql_mode` could change how the guard's lexer and the server read quotes.**
  `NO_BACKSLASH_ESCAPES` and `ANSI_QUOTES` change what a backslash and a double
  quote mean, so the guard could approve SQL the server then reads differently.
  `sql_mode` is now a guarded variable in every scope and on every driver:
  `SET sql_mode`, `SET SESSION sql_mode`, `SET @@sql_mode`, the `@@SESSION.` /
  `@@GLOBAL.` forms, a version comment around them and `set_config('sql_mode',
  …)` are refused. Reading it (`SELECT @@sql_mode`) is unaffected. On `mysql`
  and `mariadb` the executor also reads `@@SESSION.sql_mode` before running the
  SQL and fails the request, without running anything, when it contains
  `NO_BACKSLASH_ESCAPES` or `ANSI_QUOTES`. Today QueryProxy sets a strict
  `sql_mode` on every connection it opens (Laravel's `strict` option), which
  replaces the server's default, so the check only fires if that setting
  changes or something in between alters the mode; a connection that does end
  up with either flag is refused until the flag is removed. The `/*M!`
  behaviour on the `mysql` driver is unchanged and now pinned by a regression
  test.
- **Three `SET` spellings were not judged.** `SET GLOBAL general_log := 1`
  (a space before `:=`) read as a label and passed the dangerous-variable list;
  `SET @@general_log = 1` (no scope) was not judged at all; and in MariaDB
  `SET STATEMENT … FOR <statement>` the statement after `FOR` skipped every
  rule, so `SET STATEMENT max_statement_time=1 FOR DELETE FROM t` ran without
  a `WHERE` check. All three are now judged. The statement after `FOR` goes
  through the same rules as a top-level one (type, denylist, `WHERE`, `LIMIT`,
  nesting depth); `SET STATEMENT` without a statement after `FOR` is rejected.
  **Behaviour change:** `SET @@<name>` is read as a session variable on every
  driver, so on PostgreSQL (and when the target database is unknown)
  `SET @@general_log = 1` is now refused.

### Notes (0.2.3)

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval. Dynamic SQL
  inside them is now refused, but a plainly written `DELETE` without `WHERE`
  in a `DO` body still passes the guard.
- Not covered yet: DML against system catalogs (`UPDATE pg_language`,
  `UPDATE pg_proc SET prosrc …`; these need a superuser) and `ALTER SYSTEM SET`.
  Multibyte character sets that change backslash handling (`SET NAMES big5`,
  `sjis`, `gbk`) are not checked either.

## [0.2.2] - 2026-10-03

### Added (0.2.2)

- **Live PostgreSQL guard tests.** A `live-pgsql` Pest group in `tests/Live`
  runs the guard against a real PostgreSQL server and checks both the verdict
  and the effect on the server (settings, `dblink_exec`,
  `pg_terminate_backend`, `pg_cancel_backend`, `EXPLAIN ANALYZE DELETE`,
  `DO` blocks and more). It is skipped unless `QUERYPROXY_LIVE_PGSQL_HOST` is
  set. `docker-compose.test.yml` starts a throwaway PostgreSQL 17 for local runs,
  and the new `live-pgsql` GitHub Actions workflow runs the group in CI.

### Fixed (0.2.2)

- **The queue timing warning missed `queue:listen` and `queue:work --once`.**
  The warning that `retry_after` is at or below the execution timeout was
  logged only when a daemon `queue:work` started. It is now also logged when
  `queue:listen` or `queue:work --once` starts, once per process; the
  short-lived `queue:work --once` children that `queue:listen` spawns do not
  repeat it.
- **The Compose worker example hard-coded `--timeout=310`.** `docker-compose.yml`
  now passes the timeout the image entrypoint derives
  (`QUERYPROXY_WORKER_TIMEOUT`, `QUERYPROXY_EXECUTION_TIMEOUT` + 10), so
  changing `QUERYPROXY_EXECUTION_TIMEOUT` in the example also moves the worker
  timeout. The example needs the image's default entrypoint.

### Security (0.2.2)

- **PostgreSQL settings that load code or lift protections were not guarded.**
  The dangerous-variable list now also names `session_preload_libraries`,
  `local_preload_libraries`, `dynamic_library_path`,
  `default_transaction_read_only`, `transaction_read_only`,
  `session_replication_role`, `allow_system_table_mods`,
  `lo_compat_privileges`, `zero_damaged_pages` and `ignore_checksum_failure`.
  **Behaviour change:** on PostgreSQL (and when the target database is
  unknown) these are refused in every scope: `SET <name>`, `SET LOCAL <name>`,
  `SET SESSION <name>`, the `TO` spelling and `set_config('<name>', …)`.
  Before this release no PostgreSQL name was on the list. On MySQL, MariaDB,
  SQLite and SQL Server the `SET` rule is unchanged (only the persistent scopes
  `GLOBAL`, `PERSIST` and `PERSIST_ONLY` are judged), while
  `set_config('<name>', …)` with one of the new names is refused on every driver.
  Harmless settings such as `work_mem` or `search_path` still pass.
  `QUERYPROXY_EXTRA_DANGEROUS_VARIABLES` still extends the list.
- **MariaDB `ANALYZE <statement>` ran its statement unchecked.** Like
  `EXPLAIN ANALYZE`, MariaDB's `ANALYZE [FORMAT = x] <statement>` executes the
  statement. It is now judged as the statement inside it (type, denylist,
  `WHERE` rule, `LIMIT`, nesting depth). Only `SELECT`, `WITH`, `INSERT`,
  `REPLACE`, `UPDATE`, `DELETE` or a parenthesized query may follow; anything
  else (`DROP`, `TRUNCATE`, `SET`, a nested `ANALYZE`, …), and a parenthesized
  `(ANALYZE <statement>)`, is rejected. The mysql driver and an unknown driver
  are judged the same way. `ANALYZE TABLE …` (maintenance) is unchanged.
- **Multibyte comments could hide the real statement from `EXPLAIN ANALYZE` and
  `ANALYZE`.** The guard cut the inner statement at a character offset where a
  byte offset was needed, so a comment with non-ASCII text in front of it
  shifted the cut and let a `DELETE` without `WHERE` through. Offsets are now
  converted to bytes.
- **MariaDB executable comments were not inspected.** `/*M!100000 … */` was
  treated as a plain comment, so its body ran on MariaDB without being checked.
  **On the `mariadb` driver** its body is now read as SQL, whatever the version
  condition. On the `mysql` driver `/*M!` is still a plain comment, so connect
  a MariaDB server with the `mariadb` driver. Nesting deeper than 3 levels is
  rejected, and when the target database is unknown a `/*M!` comment is
  rejected outright.
- **Version-conditional comments (`/*!NNNNN … */`) could hide a clause from the
  guard.** A server skips the body when its version is lower than the one
  written, so a `WHERE` or `LIMIT` inside it may or may not exist. On the
  `mysql` and `mariadb` drivers the statement is now checked twice, once as if
  the bodies run and once as if the server skips them, and is rejected when
  either reading is refused or when the two readings differ in statement count,
  transaction state, type or prepared text. **Behaviour change:** these are now
  rejected on `mysql` and `mariadb`, which 0.2.1 accepted: different versions in
  one request (`SELECT /*!40101 1 */, /*!50503 2 */` is one), a version of other
  than 5 digits (5 or 6 on MariaDB), a versioned comment nested in or holding
  another comment, and one with `*/` inside a string or line comment in its
  body. A request with a single version and a plain body is unaffected.
- **SQL that was not valid UTF-8 slipped past the lexer.** A stray byte inside
  a comment (`DROP DATABASE prod /* é\xFF */`) made the guard read an empty
  statement. SQL that is not valid UTF-8 is now rejected
  (`SQL must be valid UTF-8.`), and so is text in which the lexer finds no
  statement (`QueryProxy could not read this SQL: …`).
- **`UPDATE` / `DELETE` with an always-true `WHERE` passed.** `WHERE 1 = 1`,
  `WHERE true`, `WHERE 'a' = 'a'` or an `OR` with such an operand counted as a
  `WHERE` clause. **Behaviour change:** these are now rejected like a missing
  `WHERE`, in every statement the guard looks into (CTE bodies, `EXPLAIN
  ANALYZE`, MariaDB `ANALYZE`, T-SQL batches, dynamic SQL). Detection is
  static: literals, comparisons, `IS [NOT] NULL|TRUE|FALSE`, `AND`, `OR`,
  `NOT` and parentheses are evaluated, and an operand it cannot evaluate never
  counts as true. A `WHERE` clause nested too deeply or too large to evaluate
  is rejected as well (`… too complex to verify`).
- **A byte-order mark or Unicode whitespace could hide keywords.** The lexer
  glues characters such as U+FEFF, U+00A0, U+2028 or U+3000 to the neighbouring
  word while a server may read them as separators, so `\u{FEFF}DROP DATABASE
  prod` showed no `DROP`. **Behaviour change:** these characters are rejected
  outside string literals, quoted identifiers and comments, on every driver and
  inside dynamic SQL.
- **PostgreSQL reads `\` and `#` differently from the lexer.** On PostgreSQL
  (and when the target database is unknown) a backslash is now rejected
  anywhere except in comments, dollar-quoted bodies, `E'…'` strings and
  well-formed `U&` strings, and `#` is rejected outside literals, quoted
  identifiers and comments. A plain string such as `'a\'` is therefore
  rejected on PostgreSQL even though MySQL and MariaDB behave as before.

### Notes (0.2.2)

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval, where the
  reviewer sees the whole body. A `DELETE` without `WHERE` inside a
  `DO` block therefore passes the guard.

## [0.2.1] - 2026-10-02

### Fixed (0.2.1)

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

### Security (0.2.1)

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

### Upgrading (0.2.1)

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

## [0.2.0] - 2026-09-18

### Changed (0.2.0)

- **Breaking — set `TRUSTED_PROXIES` when running behind a reverse proxy.** It is
  empty by default. Until it names your proxy, `X-Forwarded-Proto` is ignored, so
  HTTPS is not detected and audit entries record the proxy's address rather than
  the client's. Never use `*`.
- **Breaking — a system administrator must set the ChatOps signing secret.**
  DBAs keep control of the webhook URL and the enabled flag, but can no longer
  enter or rotate the secret. Existing installations should rotate it once after
  upgrading: any DBA who configured ChatOps already knows the current value.
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

### Security (0.2.0)

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

## [0.1.3] - 2026-09-08

### Changed (0.1.3)

- Image bases moved to `php:8.5-fpm-alpine` and `node:26-alpine`. OPcache is no
  longer installed explicitly: PHP 8.5 links it statically and enables it by
  default, and `docker-php-ext-install opcache` fails there.
- Release workflow actions updated to `docker/login-action@v4`,
  `setup-buildx-action@v4`, `setup-qemu-action@v4` and `metadata-action@v6`.

## [0.1.2] - 2026-09-08

### Added (0.1.2)

- `php artisan queryproxy:create-admin` creates the first administrator, and
  `QUERYPROXY_ADMIN_EMAIL` / `QUERYPROXY_ADMIN_PASSWORD` / `QUERYPROXY_ADMIN_NAME`
  create it on first boot — a fresh instance no longer needs demo seeding to
  have an account to log in with.
- Container `HEALTHCHECK` against `/up`, so `docker run` reports health without
  a compose file.

### Changed (0.1.2)

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

## [0.1.1] - 2026-09-08

### Added (0.1.1)

- `.dockerignore`, so local `docker build` uses the same clean context as CI
  instead of copying host `vendor/`, `node_modules/` and `.env` into the image.

### Fixed (0.1.1)

- Demo seeding (`QUERYPROXY_SEED_DEMO=true`) crashed the production container
  on first boot: the seeder relied on Faker, which is a dev-only dependency and
  absent from `--no-dev` installs. The seeder no longer uses factories.

## [0.1.0] - 2026-09-08

The first public release.

### Added (0.1.0)

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

[Unreleased]: https://github.com/QueryProxy/QueryProxy/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.7...v0.3.0
[0.2.7]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.6...v0.2.7
[0.2.6]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.5...v0.2.6
[0.2.5]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
