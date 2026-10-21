<?php

namespace App\Services\Audit\Sinks;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hook AuditRecorder calls after an audit record is stored: queues one
 * delivery per enabled sink, once the record is really committed (an audit
 * write inside a transaction that rolls back is never exported).
 *
 * It never throws. A failure to queue (queue backend down, bad config) is
 * logged and the audit write goes on; that event is then missing from the
 * sinks, which is why the database stays the system of record and the hash
 * chain (ADR-020) the way to notice gaps.
 */
class AuditEventDispatcher
{
    public function __construct(private readonly SinkRegistry $sinks) {}

    public function dispatch(AuditLog $log): void
    {
        try {
            $names = $this->sinks->enabledNames();

            if ($names === []) {
                return;
            }

            $id = (int) $log->getKey();

            DB::afterCommit(function () use ($names, $id) {
                foreach ($names as $name) {
                    try {
                        dispatch(new DeliverAuditEvent($name, $id));
                    } catch (Throwable $e) {
                        $this->queueFailed($name, $id, $e);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->queueFailed('*', (int) $log->getKey(), $e);
        }
    }

    private function queueFailed(string $sink, int $id, Throwable $e): void
    {
        try {
            Log::error('QueryProxy: could not queue an audit event for the SIEM sinks.', [
                'sink' => $sink,
                'audit_log_id' => $id,
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // Logging must not break the audit write either.
        }
    }
}
