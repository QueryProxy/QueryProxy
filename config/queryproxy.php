<?php

use App\Enums\ChatSqlMode;
use App\Services\Sql\SqlInspector;
use App\Support\ExecutionTimeout;

/*
| QUERYPROXY_EXECUTION_TIMEOUT is validated by ExecutionTimeout::resolve(), the
| same rule docker/entrypoint.sh applies. The fallback to 300 is silent here:
| the logger is not available while config files load, and the entrypoint
| already warns about a bad value at container start.
*/
$executionTimeout = ExecutionTimeout::resolve(env('QUERYPROXY_EXECUTION_TIMEOUT', '300'));

return [

    /*
    |--------------------------------------------------------------------------
    | SELECT limit guards
    |--------------------------------------------------------------------------
    | Every SELECT without a LIMIT gets `select_default_limit` injected.
    | A LIMIT above `select_hard_limit` is clamped down to it.
    */

    'select_default_limit' => (int) env('QUERYPROXY_SELECT_DEFAULT_LIMIT', 1000),

    'select_hard_limit' => (int) env('QUERYPROXY_SELECT_HARD_LIMIT', 10000),

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    | `result_disk` must be a PRIVATE filesystem disk. Result files are only
    | meant to be reachable through the download route, which enforces the
    | request policy; a publicly visible disk would serve them over a guessable
    | URL instead. QueryExecutor refuses to run a read request when this points
    | at `public` or at any disk configured with `visibility => public`.
    */

    'execution_timeout' => $executionTimeout,

    /*
    |--------------------------------------------------------------------------
    | Database lock timeout
    |--------------------------------------------------------------------------
    |
    | Seconds a statement waits for a lock on the target database before the
    | database gives up (pgsql lock_timeout, mysql / mariadb
    | innodb_lock_wait_timeout, sqlsrv LOCK_TIMEOUT, sqlite busy timeout).
    | Digits only, 1 to 3600; anything else falls back to 5 (see ExecutionTimeout).
    | The statement timeout itself is derived from the execution timeout
    | above, minus a margin, so the database stops before the job does.
    |
    */

    'lock_timeout' => ExecutionTimeout::resolveLock(env('QUERYPROXY_LOCK_TIMEOUT', '5')),

    /*
    |--------------------------------------------------------------------------
    | Connection slots
    |--------------------------------------------------------------------------
    |
    | How many requests may run against one target connection at the same time
    | (a cache lock per slot). A request that finds every slot taken goes back
    | on the queue and starts again after `connection_slot_retry_delay`
    | seconds; it stays "queued" meanwhile. 0 disables the limit. With more
    | workers than slots, the extra workers simply re-queue jobs.
    */

    'connection_slots' => max(0, (int) env('QUERYPROXY_CONNECTION_SLOTS', 2)),

    'connection_slot_retry_delay' => max(1, (int) env('QUERYPROXY_CONNECTION_SLOT_RETRY_DELAY', 5)),

    'result_disk' => env('QUERYPROXY_RESULT_DISK', 'local'),

    'result_ttl_days' => (int) env('QUERYPROXY_RESULT_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Statement guard lists
    |--------------------------------------------------------------------------
    | `untrusted_languages` names the procedural languages that execute outside
    | the database's permission system. Installing one (CREATE EXTENSION) or
    | asking a DO block to run in one is refused; every other extension —
    | pg_stat_statements, pgcrypto, uuid-ossp, postgis — and a plain plpgsql
    | DO block are ordinary DBA work and pass. A language is also refused when
    | its name only follows PostgreSQL's "pl...u" convention for an untrusted
    | language, so this list does not have to name every one of them.
    |
    | `dangerous_variables` names the server settings whose value writes a
    | file, loads code into the server process or turns a protection off. On
    | MySQL / MariaDB, SET GLOBAL / PERSIST on one of them is refused and
    | SESSION-scoped writes are not guarded; on PostgreSQL, and when the
    | driver is unknown, SET in any scope (plain, SESSION, LOCAL) is refused.
    | set_config() with a listed name is refused on every driver. sql_mode
    | is the exception to the scope rule: it changes how quotes and
    | backslashes are read, so a write to it is refused in every scope.
    | `role` and `session_authorization` are listed as well, so set_config()
    | cannot switch the session's role or user. max_connections, wait_timeout,
    | work_mem and the rest pass.
    | Names are matched exactly, case-insensitively — there is no wildcard,
    | so related variables (`general_log`, `general_log_file`) are listed
    | one by one.
    |
    | The three function lists judge a call by its effect. A call to one of
    | `blocked_functions` (server control, dblink) is refused. A call to one
    | of `state_changing_functions` (sequences, advisory and named locks) or
    | `resource_consuming_functions` (sleep, benchmark) makes the statement a
    | write, so it goes through approval instead of passing as a read. Names
    | match case-insensitively; an entry ending in "*" matches every name with
    | that prefix. set_config() is judged against `dangerous_variables`.
    |
    | Every list is the class constant of App\Services\Sql\SqlInspector plus
    | whatever the environment adds. The env values can only EXTEND a list:
    | they are merged in, never substituted, and SqlInspector merges its own
    | constants back on top, so a shortened list here cannot disarm the guard.
    */

    'untrusted_languages' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::UNTRUSTED_LANGUAGES,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_UNTRUSTED_LANGUAGES', ''))),
    )))),

    'dangerous_variables' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::DANGEROUS_VARIABLES,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_DANGEROUS_VARIABLES', ''))),
    )))),

    'blocked_functions' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::BLOCKED_FUNCTIONS,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_BLOCKED_FUNCTIONS', ''))),
    )))),

    'state_changing_functions' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::STATE_CHANGING_FUNCTIONS,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_STATE_CHANGING_FUNCTIONS', ''))),
    )))),

    'resource_consuming_functions' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::RESOURCE_CONSUMING_FUNCTIONS,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_RESOURCE_CONSUMING_FUNCTIONS', ''))),
    )))),

    // SQL Server system procedures QueryProxy refuses to invoke ("xp_*"
    // matches a prefix). The env list can only add to the floor.
    'blocked_procedures' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::BLOCKED_PROCEDURES,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_BLOCKED_PROCEDURES', ''))),
    )))),

    // SQLite pragmas QueryProxy lets through — read-only, and never with a
    // value. The env list can only add to the floor; an added pragma is
    // allowed only in its bare form ("PRAGMA x"), since "PRAGMA x(v)" sets it.
    'sqlite_allowed_pragmas' => array_values(array_unique(array_filter(array_merge(
        SqlInspector::SQLITE_ALLOWED_PRAGMAS,
        array_map('trim', explode(',', (string) env('QUERYPROXY_EXTRA_SQLITE_PRAGMAS', ''))),
    )))),

    /*
    |--------------------------------------------------------------------------
    | Connection target restrictions
    |--------------------------------------------------------------------------
    | SQLite targets inside the application directory and connections to the
    | app's own database are always blocked. Optionally pin SQLite files to a
    | directory and blocklist extra hosts (comma-separated).
    */

    'sqlite_allowed_dir' => env('QUERYPROXY_SQLITE_ALLOWED_DIR'),

    'connection_host_denylist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('QUERYPROXY_CONNECTION_HOST_DENYLIST', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | ChatOps
    |--------------------------------------------------------------------------
    | Outbound webhook URLs must resolve to one of these host patterns (SSRF
    | guard). Extend with a comma-separated list when your Teams flow lives on
    | a different domain. `chat_include_sql` controls the 400-char SQL preview in
    | the notification messages: `full`, `literals_redacted` (default; string and
    | number literals replaced by `?`) or `none`. The legacy booleans still work:
    | true -> full, false -> none (see App\Enums\ChatSqlMode).
    */

    'chat_webhook_allowed_hosts' => array_values(array_filter(array_merge(
        ['hooks.slack.com', '*.webhook.office.com', '*.logic.azure.com', '*.powerplatform.com'],
        array_map('trim', explode(',', (string) env('QUERYPROXY_CHAT_WEBHOOK_ALLOWED_HOSTS', ''))),
    ))),

    'chat_include_sql' => ChatSqlMode::fromConfig(env('QUERYPROXY_CHAT_INCLUDE_SQL'))->value,

    /*
    |--------------------------------------------------------------------------
    | Pending request reminder
    |--------------------------------------------------------------------------
    | A request still pending after this many hours is reminded about once (the
    | team's DBAs and admins in-app, plus the chat channels with a fresh action
    | token). Requests never expire. 0 disables the reminder only: chat cards
    | whose buttons timed out are still re-posted (at most daily) while a
    | request stays pending.
    */

    'pending_remind_after_hours' => max(0, (int) env('QUERYPROXY_PENDING_REMIND_AFTER', 4)),

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication policy
    |--------------------------------------------------------------------------
    | none    2FA is optional for everyone.
    | admins  Required for system admins.
    | dba     Required for system admins and anyone with a DBA role.
    | all     Required for every account.
    */

    'require_two_factor' => env('QUERYPROXY_REQUIRE_2FA', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Network trust
    |--------------------------------------------------------------------------
    | Both lists live here rather than being read with env() at runtime: the
    | container caches the configuration on every boot, and once a config cache
    | exists Laravel never loads .env again, so a runtime env() call silently
    | returns null for anything written to that file.
    |
    | `trusted_proxies` names the reverse proxies allowed to set X-Forwarded-*.
    | Empty means no proxy is trusted, which is correct when the app is reached
    | directly. Never use "*": any client could then forge X-Forwarded-For,
    | which hands out unlimited login and 2FA attempts (both throttles are keyed
    | per IP) and writes a false client IP into the audit trail.
    |
    | `trusted_hosts` adds hostnames this installation answers to, on top of
    | APP_URL's host. It exists so a multi-domain deployment keeps working while
    | a forged Host header still cannot rewrite password-reset links.
    */

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),

    'trusted_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_HOSTS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | SIEM sinks (ADR-021)
    |--------------------------------------------------------------------------
    | Every audit event can also be exported to external systems. All sinks are
    | off by default; `sinks` is a comma-separated list of the ones to turn on
    | (`stdout`, `syslog`, `webhook`, any combination). Delivery runs on the
    | queue after the audit record is committed, so a sink that is down never
    | blocks or rolls back the audit write. Webhook and syslog deliveries are
    | retried; one that keeps failing lands in `failed_jobs`, which is the
    | dead-letter queue. Delivery is at-least-once: receivers dedupe on `id`.
    |
    | Run a queue worker on `queue` (the default worker already serves
    | `default`). The event format is docs/audit-event.schema.json.
    */

    'siem' => [
        'sinks' => array_values(array_filter(array_map(
            fn ($name) => strtolower(trim($name)),
            explode(',', (string) env('QUERYPROXY_SIEM_SINKS', '')),
        ))),

        'queue' => (string) env('QUERYPROXY_SIEM_QUEUE', 'default'),

        // JSON lines, one event per line. Container logging picks up stdout.
        'stdout' => [
            'stream' => (string) env('QUERYPROXY_SIEM_STDOUT_STREAM', 'php://stdout'),
        ],

        // RFC 5424 message with the JSON event as its body.
        'syslog' => [
            'host' => (string) env('QUERYPROXY_SIEM_SYSLOG_HOST', ''),
            // Empty = the standard port of the transport: 514 (udp/tcp), 6514 (tls, RFC 5425).
            'port' => (int) env('QUERYPROXY_SIEM_SYSLOG_PORT') ?: (strtolower((string) env('QUERYPROXY_SIEM_SYSLOG_TRANSPORT', 'udp')) === 'tls' ? 6514 : 514),
            'transport' => strtolower((string) env('QUERYPROXY_SIEM_SYSLOG_TRANSPORT', 'udp')), // udp | tcp | tls
            'framing' => strtolower((string) env('QUERYPROXY_SIEM_SYSLOG_FRAMING', 'octet-counting')), // tcp/tls: octet-counting | newline
            'facility' => (int) env('QUERYPROXY_SIEM_SYSLOG_FACILITY', 13), // 13 = log audit
            'app_name' => (string) env('QUERYPROXY_SIEM_SYSLOG_APP_NAME', 'queryproxy'),
            'hostname' => (string) env('QUERYPROXY_SIEM_SYSLOG_HOSTNAME', ''),
            'tls_ca_file' => (string) env('QUERYPROXY_SIEM_SYSLOG_TLS_CA_FILE', ''),
            'timeout' => (int) env('QUERYPROXY_SIEM_SYSLOG_TIMEOUT', 5),
        ],

        // HTTPS POST signed with `X-QueryProxy-Signature: sha256=<hmac>`.
        // The secret must be at least 16 characters.
        'webhook' => [
            'url' => (string) env('QUERYPROXY_SIEM_WEBHOOK_URL', ''),
            'secret' => (string) env('QUERYPROXY_SIEM_WEBHOOK_SECRET', ''),
            'timeout' => (int) env('QUERYPROXY_SIEM_WEBHOOK_TIMEOUT', 5),
            'tries' => (int) env('QUERYPROXY_SIEM_WEBHOOK_TRIES', 6),
        ],
    ],

];
