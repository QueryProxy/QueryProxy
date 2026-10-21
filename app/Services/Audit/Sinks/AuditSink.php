<?php

namespace App\Services\Audit\Sinks;

/**
 * A destination audit events are copied to (ADR-021). Sinks receive the
 * event after the audit record is committed, from the queue; a sink that
 * cannot deliver throws, and the job is retried and finally parked in
 * `failed_jobs`.
 *
 * A sink's exception message ends up in `failed_jobs` and the log, so it
 * must never carry a URL, token or secret.
 */
interface AuditSink
{
    /** The name used in QUERYPROXY_SIEM_SINKS. */
    public function name(): string;

    /**
     * @param  array<string, mixed>  $event  See AuditEventFormatter.
     *
     * @throws SinkDeliveryException
     */
    public function deliver(array $event): void;

    /** Attempts before the delivery is parked in `failed_jobs`. */
    public function tries(): int;

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array;
}
