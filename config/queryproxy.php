<?php

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
    */

    'execution_timeout' => (int) env('QUERYPROXY_EXECUTION_TIMEOUT', 300),

    'result_disk' => env('QUERYPROXY_RESULT_DISK', 'local'),

    'result_ttl_days' => (int) env('QUERYPROXY_RESULT_TTL_DAYS', 30),

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
