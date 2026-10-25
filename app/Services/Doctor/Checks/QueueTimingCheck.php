<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/**
 * `retry_after` must outlast the worker's own --timeout, which is the query
 * timeout plus a 10 second margin (docker/entrypoint.sh). Otherwise a running
 * query is handed to a second worker, or marked failed, while it still runs.
 */
class QueueTimingCheck extends BaseCheck
{
    /** Seconds the worker's --timeout adds to the execution timeout. */
    public const WORKER_MARGIN = 10;

    public function name(): string
    {
        return 'queue_timing';
    }

    public function run(): CheckResult
    {
        $queue = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$queue}.retry_after");

        if ($retryAfter === null) {
            return $this->skip("The \"{$queue}\" queue connection has no retry_after.");
        }

        $executionTimeout = (int) config('queryproxy.execution_timeout');
        // Derived the same way as docker/entrypoint.sh, which ignores any
        // QUERYPROXY_WORKER_TIMEOUT set by the operator.
        $workerTimeout = $executionTimeout + self::WORKER_MARGIN;

        if ((int) $retryAfter <= $workerTimeout) {
            return $this->fail("retry_after ({$retryAfter}s) is not greater than the worker timeout ({$workerTimeout}s, execution timeout {$executionTimeout}s); a long query would be started a second time. Raise {$queue} retry_after or lower QUERYPROXY_EXECUTION_TIMEOUT.");
        }

        return $this->ok("retry_after {$retryAfter}s > worker timeout {$workerTimeout}s (execution timeout {$executionTimeout}s).");
    }
}
