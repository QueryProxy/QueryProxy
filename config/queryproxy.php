<?php

use App\Services\Sql\SqlInspector;

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

    'execution_timeout' => (int) env('QUERYPROXY_EXECUTION_TIMEOUT', 300),

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
    | `dangerous_variables` names the server variables whose persistent value
    | writes a file, loads code into the server process or turns a protection
    | off. SET GLOBAL / PERSIST on one of them is refused; max_connections,
    | wait_timeout, sql_mode and the rest pass, and SESSION-scoped writes are
    | never guarded. Names are matched exactly, case-insensitively — there is
    | no wildcard, so related variables (`general_log`, `general_log_file`)
    | are listed one by one.
    |
    | Both lists are the class constants of App\Services\Sql\SqlInspector plus
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
    | a different domain. `chat_include_sql` embeds a 400-char SQL preview in
    | the notification messages.
    */

    'chat_webhook_allowed_hosts' => array_values(array_filter(array_merge(
        ['hooks.slack.com', '*.webhook.office.com', '*.logic.azure.com'],
        array_map('trim', explode(',', (string) env('QUERYPROXY_CHAT_WEBHOOK_ALLOWED_HOSTS', ''))),
    ))),

    'chat_include_sql' => (bool) env('QUERYPROXY_CHAT_INCLUDE_SQL', true),

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

];
