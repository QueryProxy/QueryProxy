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

];
