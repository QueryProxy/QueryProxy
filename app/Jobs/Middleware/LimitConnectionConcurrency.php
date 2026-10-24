<?php

namespace App\Jobs\Middleware;

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\QueryRequest;
use App\Services\Metrics\MetricsRecorder;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Caps how many requests run against one target connection at a time.
 *
 * A "slot" is a cache lock named `conn:{id}:slot:{n}`, n = 1..N with N from
 * `queryproxy.connection_slots`. A job takes the first free slot for its
 * connection and holds it while it executes. When every slot is taken the job
 * does not run and does not fail: a fresh copy goes back on the queue with a
 * delay and the request stays `queued`, so the requester sees it waiting for
 * a free slot rather than an error.
 *
 * The locks live in the application cache, which is the database store by
 * default (ADR-002) and works across any number of workers. A lock whose
 * holder died frees itself after its TTL (execution timeout + margin).
 */
class LimitConnectionConcurrency
{
    /** Seconds a slot outlives the job's execution timeout, in case the holder dies. */
    public const LOCK_MARGIN = 30;

    /**
     * @param  Closure(ExecuteQueryRequest): mixed  $next
     */
    public function handle(ExecuteQueryRequest $job, Closure $next): void
    {
        $slots = (int) config('queryproxy.connection_slots', 2);

        // Disabled, or the sync driver: a sync job cannot be re-queued for
        // later, it would just run again right away.
        if ($slots < 1 || $job->job instanceof SyncJob) {
            $next($job);

            return;
        }

        $request = $job->request;

        // A request that is no longer waiting (cancelled, already running on
        // a duplicate copy, ...) goes straight to the executor, whose
        // conditional claim ends it quietly. Parking it would loop forever.
        $waiting = QueryRequest::whereKey($request->id)
            ->whereIn('status', [QueryRequestStatus::Queued, QueryRequestStatus::Approved])
            ->exists();

        if (! $waiting) {
            $next($job);

            return;
        }

        $ttl = (int) config('queryproxy.execution_timeout', 300) + self::LOCK_MARGIN;

        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::lock(self::lockName($request->connection_id, $slot), $ttl);

            if (! $lock->get()) {
                continue;
            }

            try {
                $next($job);
            } finally {
                $lock->release();
            }

            return;
        }

        $delay = max(1, (int) config('queryproxy.connection_slot_retry_delay', 5));

        $this->noteDeferred($request, $slots);
        $job->requeue($delay);
    }

    public static function lockName(int $connectionId, int $slot): string
    {
        return "conn:{$connectionId}:slot:{$slot}";
    }

    /**
     * Leave a trace of the wait: one audit entry and one info log line per
     * request (not one per retry, which would be one every few seconds for
     * as long as the slots stay busy); later retries log at debug level only.
     */
    private function noteDeferred(QueryRequest $request, int $slots): void
    {
        $context = [
            'query_request_id' => $request->id,
            'connection_id' => $request->connection_id,
            'slots' => $slots,
        ];

        try {
            $first = Cache::add("conn-slot-deferred:{$request->id}", true, 3600);
        } catch (Throwable) {
            $first = false;
        }

        if (! $first) {
            Log::debug('Query request still waiting for a free connection slot.', $context);

            return;
        }

        Log::info('Query request waiting for a free connection slot.', $context);

        app(MetricsRecorder::class)->slotDeferred();

        try {
            audit()->record(QueryRequest::SLOT_DEFERRED_ACTION, actor: $request->reviewer, request: $request, metadata: [
                'reason' => 'connection_slots_busy',
                'slots' => $slots,
            ]);
        } catch (Throwable $e) {
            Log::warning('Recording a deferred query request failed.', [
                'query_request_id' => $request->id,
                'exception' => $e::class,
            ]);
        }
    }
}
