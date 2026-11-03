# PostgreSQL-compatible systems

QueryProxy talks to PostgreSQL through its `pgsql` driver. Systems that speak
the PostgreSQL protocol can be added as a connection with the driver
**PostgreSQL**, but each one differs from PostgreSQL in places the application
relies on: how a running statement is stopped, how read-only mode works, how
locks time out. This page lists what has been tested and what is known to
differ.

"Tested in CI" means the integration tests (`tests/Integration`, Pest group
`integration`) run against a real server of that system in the
`integration` workflow, on every pull request. "Not tested" means nothing has
been run against it: it may work, and nothing here promises it.

## Status

| System | Version tested | Status | Tested by |
| --- | --- | --- | --- |
| CockroachDB | v25.4.19 | Tested in CI, with known differences | job `integration (cockroachdb 25.4)` |
| YugabyteDB | 2025.2.8.0-b60 (PostgreSQL 15.12-YB) | Tested in CI, with known differences | job `integration (yugabytedb 2025.2)` |
| Aurora PostgreSQL | - | Not tested | - |
| Neon | - | Not tested | - |
| Supabase | - | Not tested | - |

These are target databases only. The application's own database stays
SQLite, MySQL, MariaDB, PostgreSQL or SQL Server as before; CockroachDB and
YugabyteDB are not supported as the application database.

The integration suite exercises read-only enforcement, statement and lock
timeouts, stopping a running statement, streaming of large results and
required TLS. The tables below say how each system does on those.

| Behaviour | CockroachDB | YugabyteDB |
| --- | --- | --- |
| The database refuses writes and DDL in read requests | Yes (see "Read-only mode") | Yes |
| The SQL guard knows the system's own statements | **No**, PostgreSQL dialect only (see "SQL guard") | PostgreSQL dialect; YugabyteDB-only statements not reviewed |
| Statement timeout cuts a long read or write | Yes | Yes |
| Lock timeout cuts a wait for a row lock | Yes | **No** (see "Lock waits") |
| Stop ends a running write | Yes | Yes |
| Stop ends a running read | **No**, the statement timeout ends it (see "Stopping a statement") | Yes |
| Large results are streamed (server-side cursor) | Yes | Yes |
| Required TLS refuses a server without TLS; CA verification | Yes | Not tested |
| Connection test reports the session as encrypted | Reports "unknown" | Not tested |

## CockroachDB

Add the connection with the driver **PostgreSQL**, port `26257` (the SQL
port), and the database name. Use a secure cluster (TLS and password logins).
An insecure node (`--insecure`) accepts no passwords and offers no TLS, so it
cannot be used with the TLS requirement of new connections and cannot hold a
read-only login.

Known differences:

- **Stopping a statement.** CockroachDB has no `pg_cancel_backend`.
  QueryProxy records the session id (`SHOW session_id`) next to the process
  number and stops a statement with
  `CANCEL QUERIES SELECT query_id FROM [SHOW CLUSTER STATEMENTS] WHERE session_id = ...`.
  The session stays, as with PostgreSQL. The login must be allowed to see its
  own statements (every login is; the integration suite stops a write run by a login
  without the `admin` role) and the nodes must be able to reach each
  other at their advertised address: a node advertising `0.0.0.0` makes
  `SHOW CLUSTER STATEMENTS` come back empty and the stop is not delivered.
- **Stopping a read.** A read request is run through a server-side cursor
  (`DECLARE ... CURSOR`). CockroachDB evaluates the query of a cursor when it is
  declared and ignores `CANCEL QUERIES` and `CANCEL SESSION` until that is done.
  "Stop" marks the request as cancelled at once, but the statement itself
  keeps running until it finishes or the database-side statement timeout cuts
  it (QueryProxy always sets one: the execution timeout minus 5 seconds).
  A stopped write is interrupted at once.
- **Read-only mode.** CockroachDB commits the open transaction before a DDL
  statement (setting `autocommit_before_ddl`), the way MySQL does, so a
  `READ ONLY` transaction alone would let DDL through. QueryProxy therefore
  also sets `default_transaction_read_only` for the whole session on
  CockroachDB, and the server then refuses DDL and data changes in a read
  request. Unlike PostgreSQL, CockroachDB lets a statement switch its own
  transaction back to read-write (`SELECT set_config('transaction_read_only',
  'off', false)` or `SET transaction_read_only = off`). The SQL guard refuses
  those statements in read requests, but the database would not: for a
  database-side guarantee, configure a read-only login on the connection.
- **SQL guard.** The guard's deny lists are written for the PostgreSQL
  dialect and do not know statements that exist only in CockroachDB. These pass
  the guard as ordinary writes (or, for `crdb_internal.*` functions such as
  `crdb_internal.force_panic`, as reads): `CANCEL QUERY` / `CANCEL SESSION`
  (and the plural forms), `SET CLUSTER SETTING`, `EXPORT`, `IMPORT`, `BACKUP`
  and `RESTORE`. PostgreSQL's counterparts (`pg_cancel_backend` and
  `ALTER SYSTEM`, for example) are refused. On CockroachDB the protection
  against these is the login, not the guard: connect with the least-privileged
  login that does the job, **not a member of the `admin` role** and without the
  `CANCELQUERY` and `MODIFYCLUSTERSETTING` role options or the
  `EXTERNALIOIMPLICITACCESS` privilege, and add a separate read-only login for
  read requests. Widening the guard to the CockroachDB dialect is not part of
  this release (it is on the action plan).
- **TLS state.** `pg_stat_ssl` is empty on CockroachDB, so the connection test
  reports the session's encryption as unknown instead of yes or no. The TLS
  requirement itself works: a `require` connection to a node without TLS
  fails, and certificate verification (`verify-full` with a CA) works.
- **Isolation.** CockroachDB runs transactions at `SERIALIZABLE`. A
  statement can fail with a retryable error (SQLSTATE `40001`); QueryProxy
  reports it as the error of the request and does not retry.

## YugabyteDB

Add the connection with the driver **PostgreSQL**, port `5433` (YSQL), and the
database name. Session ids, `pg_cancel_backend`, `pg_stat_activity`,
`pg_stat_ssl`, read-only transactions and server-side cursors behave as in
PostgreSQL, so stopping a statement, read-only mode, the statement timeout and
streaming need no special handling.

Known differences:

- **Lock waits.** `lock_timeout` is not applied while a statement waits for a
  row lock held by another transaction (observed on 2025.2.8.0 with wait
  queues on, the default). The wait ends at the statement timeout instead. A
  single `UPDATE` run outside a transaction that is cut by the statement timeout
  during that wait **can still be applied** once the other transaction lets
  go, although the request is reported as failed (inside a transaction it is
  rolled back). Do not rely on the lock timeout or on a failed write meaning
  "nothing changed" for rows other sessions hold locks on.
- **TLS.** Not tested: the test server runs without client-to-server
  encryption.

## Not tested

Aurora PostgreSQL, Neon and Supabase speak the PostgreSQL protocol and
are expected to behave like PostgreSQL for the features above, but nothing has
been run against them. Connection poolers in transaction mode (Neon's
pooled endpoint, Supabase's pooler) are a known risk for session-level
settings such as the statement timeout outside transactions, and for the
process number used to stop a statement.

## Running the tests locally

```sh
tests/Integration/docker/generate-certs.sh
docker compose -f docker-compose.integration.yml up -d --wait cockroachdb cockroachdb-notls

QUERYPROXY_IT_DRIVER=pgsql QUERYPROXY_IT_FLAVOR=cockroachdb \
QUERYPROXY_IT_HOST=127.0.0.1 QUERYPROXY_IT_PORT=16257 \
QUERYPROXY_IT_DATABASE=queryproxy_test QUERYPROXY_IT_USERNAME=queryproxy_test \
QUERYPROXY_IT_PASSWORD=queryproxy_test \
QUERYPROXY_IT_TLS_ON_PORT=16257 QUERYPROXY_IT_TLS_OFF_PORT=16258 \
QUERYPROXY_IT_TLS_CA=tests/Integration/docker/certs/ca.crt \
./vendor/bin/pest --group=integration

docker compose -f docker-compose.integration.yml down --volumes
```

For YugabyteDB start the `yugabytedb` service (it takes a minute or two to
become healthy) and use `QUERYPROXY_IT_FLAVOR=yugabytedb`, port `15434`,
database, user and password `yugabyte`, and no `QUERYPROXY_IT_TLS_*`
variables. The tests that a system cannot pass are skipped with the reason
in the test itself.
