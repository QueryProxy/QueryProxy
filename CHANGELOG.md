# Changelog

All notable changes to QueryProxy are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow
[SemVer](https://semver.org/).

## [Unreleased]

## [0.2.5] — 2026-10-10

### Security

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

### Added

- `php artisan queryproxy:mask-error-messages [--dry-run]` masks the error
  messages already stored on query requests and the copy of them inside
  in-app "request failed" notifications, using each connection's current
  rules (a request or connection that no longer exists gets the fixed
  message). Safe to run repeatedly; the immutable audit log is left untouched
  and the run is recorded as one `error_messages.masked` entry (scanned,
  changed, replaced by the fixed message, skipped; and scanned, changed,
  replaced by the fixed message and skipped for notifications) even when it stops halfway. A record whose connection rules
  cannot be read is skipped, left unchanged and reported, and the command
  exits non-zero.
- **DDL flag on requests.** A request that contains DDL now carries a `DDL`
  badge in the approvals queue and on the request page, and the Slack and
  Teams "new request" messages say so, so the approving DBA sees it before
  deciding. The flag was already computed and stored; it was never shown.

### Fixed

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

## [0.2.4] — 2026-10-04

### Security

- **`league/commonmark` updated to 2.10.3.** 2.10.0 is affected by
  GHSA-3q6v-r5mr-hxv8 (high) and GHSA-97jj-33gv-5xf9 (medium). The release
  image scan failed on them, so the container images for 0.2.1, 0.2.2 and
  0.2.3 were never published; 0.2.4 is the first image after 0.2.0 and
  carries all of their changes.

### Fixed

- The live PostgreSQL test that lifts a read-only transaction failed when
  `pdo_pgsql` was built against libpq older than 17: the driver frees prepared
  statements with `DEALLOCATE`, which takes a snapshot and makes PostgreSQL
  refuse the switch back to read-write. The test's setup statements now run
  unprepared. The guard itself is unchanged.

## [0.2.3] — 2026-10-04

### Security

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

### Fixed

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

### Changed

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

### Notes

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval. Dynamic SQL
  inside them is now refused, but a plainly written `DELETE` without `WHERE`
  in a `DO` body still passes the guard.
- Not covered yet: DML against system catalogs (`UPDATE pg_language`,
  `UPDATE pg_proc SET prosrc …`; these need a superuser) and `ALTER SYSTEM SET`.
  Multibyte character sets that change backslash handling (`SET NAMES big5`,
  `sjis`, `gbk`) are not checked either.

## [0.2.2] — 2026-10-03

### Security

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
  `REPLACE`, `UPDATE`, `DELETE` or a parenthesized query may follow; anything else (`DROP`,
  `TRUNCATE`, `SET`, a nested `ANALYZE`, …), and a parenthesized
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
  either reading is refused or when the two readings differ in statement
  count, transaction state, type or prepared text. **Behaviour change:** these
  are now rejected on `mysql` and `mariadb`, which 0.2.1 accepted: different
  versions in one request (`SELECT /*!40101 1 */, /*!50503 2 */` is one), a
  version of other than 5 digits (5 or 6 on MariaDB), a versioned comment nested in or holding another comment, and one
  with `*/` inside a string or line comment in its body. A request with a single
  version and a plain body is unaffected.
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

### Fixed

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

### Added

- **Live PostgreSQL guard tests.** A `live-pgsql` Pest group in `tests/Live`
  runs the guard against a real PostgreSQL server and checks both the verdict
  and the effect on the server (settings, `dblink_exec`,
  `pg_terminate_backend`, `pg_cancel_backend`, `EXPLAIN ANALYZE DELETE`,
  `DO` blocks and more). It is skipped unless `QUERYPROXY_LIVE_PGSQL_HOST` is
  set. `docker-compose.test.yml` starts a throwaway PostgreSQL 17 for local runs,
  and the new `live-pgsql` GitHub Actions workflow runs the group in CI.

### Notes

- PostgreSQL `DO` blocks remain exempt from the `WHERE` rule: they are
  classified as a write and always go through human approval, where the
  reviewer sees the whole body. A `DELETE` without `WHERE` inside a
  `DO` block therefore passes the guard.

## [0.2.1] — 2026-10-02

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

[Unreleased]: https://github.com/QueryProxy/QueryProxy/compare/v0.2.5...HEAD
[0.2.5]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.5
[0.2.4]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.4
[0.2.3]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.3
[0.2.2]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.2
[0.2.1]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.1
[0.2.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.2.0
[0.1.3]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.3
[0.1.2]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.2
[0.1.1]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.1
[0.1.0]: https://github.com/QueryProxy/QueryProxy/releases/tag/v0.1.0
