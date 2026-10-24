<?php

namespace App\Services\Metrics;

use App\Enums\GuardRule;
use App\Enums\QueryRequestStatus;
use App\Enums\StatementType;

/**
 * The metrics /metrics exposes (ADR-022). Counters and histograms are stored
 * (MetricsRecorder); gauges are computed at scrape time (MetricsExporter).
 */
enum Metric: string
{
    case RequestsTotal = 'queryproxy_requests_total';
    case RequestsPending = 'queryproxy_requests_pending';
    case ApprovalLatencySeconds = 'queryproxy_approval_latency_seconds';
    case ExecutionDurationSeconds = 'queryproxy_execution_duration_seconds';
    case GuardRejectionsTotal = 'queryproxy_guard_rejections_total';
    case MaskedValuesTotal = 'queryproxy_masked_values_total';
    case SlotDeferredRequestsTotal = 'queryproxy_slot_deferred_requests_total';
    case QueueDepth = 'queryproxy_queue_depth';
    case FailedJobs = 'queryproxy_failed_jobs';

    /** Statuses a request is counted in as it enters them (queued is an internal hop). */
    public const COUNTED_STATUSES = [
        QueryRequestStatus::Pending,
        QueryRequestStatus::Approved,
        QueryRequestStatus::Rejected,
        QueryRequestStatus::Running,
        QueryRequestStatus::Completed,
        QueryRequestStatus::Failed,
        QueryRequestStatus::Cancelled,
    ];

    /** The `rule` label of queryproxy_masked_values_total: the masking rule's match type, never its (team-chosen) name. */
    public const MASK_RULE_LABELS = ['column', 'regex', 'validator'];

    public function type(): string
    {
        return match ($this) {
            self::RequestsTotal, self::GuardRejectionsTotal, self::MaskedValuesTotal, self::SlotDeferredRequestsTotal => 'counter',
            self::ApprovalLatencySeconds, self::ExecutionDurationSeconds => 'histogram',
            self::RequestsPending, self::QueueDepth, self::FailedJobs => 'gauge',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::RequestsTotal => 'Query requests, counted each time one enters a status.',
            self::RequestsPending => 'Query requests waiting for a decision.',
            self::ApprovalLatencySeconds => 'Seconds from submission to the approve or reject decision.',
            self::ExecutionDurationSeconds => 'Seconds a request ran on its target database, by driver.',
            self::GuardRejectionsTotal => 'Submitted statements the SQL guard refused, by rule family.',
            self::MaskedValuesTotal => 'Result values replaced by a masking rule, by rule type.',
            self::SlotDeferredRequestsTotal => 'Requests held back because every connection slot was busy.',
            self::QueueDepth => 'Jobs waiting on a queue.',
            self::FailedJobs => 'Jobs in the failed jobs table.',
        };
    }

    /**
     * Upper bounds in seconds, as the text used for the `le` label.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        return match ($this) {
            self::ApprovalLatencySeconds => ['1', '5', '15', '60', '300', '900', '3600', '14400', '86400'],
            self::ExecutionDurationSeconds => ['0.01', '0.05', '0.1', '0.5', '1', '2.5', '5', '10', '30', '60', '120', '300'],
            default => [],
        };
    }

    /**
     * Label sets of a counter that are always exposed, so a series exists
     * (at 0) before its first event and rate() has a starting point.
     *
     * @return list<array<string, string>>
     */
    public function zeroSeries(): array
    {
        return match ($this) {
            self::RequestsTotal => collect(self::COUNTED_STATUSES)
                ->crossJoin(StatementType::cases())
                ->map(fn (array $pair) => ['kind' => $pair[1]->value, 'status' => $pair[0]->value])
                ->all(),
            self::GuardRejectionsTotal => array_map(fn (GuardRule $rule) => ['rule' => $rule->value], GuardRule::cases()),
            self::MaskedValuesTotal => array_map(fn (string $rule) => ['rule' => $rule], self::MASK_RULE_LABELS),
            self::SlotDeferredRequestsTotal => [[]],
            default => [],
        };
    }
}
