<?php

namespace App\Services\Audit\Sinks;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one audit record to one sink (ADR-021). One job per sink, so a
 * failing destination is retried alone and never repeats a delivery that
 * already succeeded elsewhere.
 *
 * It carries only the sink name and the record id: the event is built when
 * the job runs, so no SQL, metadata or secret sits in the `jobs` table. When
 * the attempts run out the worker parks the job in `failed_jobs`, which is
 * the dead-letter queue (`php artisan queue:retry` sends it again).
 */
class DeliverAuditEvent implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 60;

    public function __construct(public string $sink, public int $auditLogId)
    {
        $this->onQueue((string) config('queryproxy.siem.queue', 'default'));
        $this->tries = $this->sinkTries();
    }

    public function handle(SinkRegistry $sinks, AuditEventFormatter $formatter): void
    {
        $sink = $sinks->make($this->sink);

        // Switched off (or broken) since the job was queued: nothing to do.
        if ($sink === null) {
            return;
        }

        $log = AuditLog::query()->find($this->auditLogId);

        if ($log === null) {
            Log::warning('QueryProxy: audit record not found for SIEM delivery.', [
                'sink' => $this->sink,
                'audit_log_id' => $this->auditLogId,
            ]);

            return;
        }

        $sink->deliver($formatter->format($log));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return app(SinkRegistry::class)->make($this->sink)?->backoff() ?? [60];
    }

    public function failed(?Throwable $exception): void
    {
        // The exception text is already free of URLs and secrets (SinkDeliveryException).
        Log::error('QueryProxy: SIEM delivery gave up; the job is in failed_jobs.', [
            'sink' => $this->sink,
            'audit_log_id' => $this->auditLogId,
            'error' => $exception instanceof SinkDeliveryException ? $exception->getMessage() : ($exception ? $exception::class : null),
        ]);
    }

    private function sinkTries(): int
    {
        return app(SinkRegistry::class)->make($this->sink)?->tries() ?? 1;
    }
}
