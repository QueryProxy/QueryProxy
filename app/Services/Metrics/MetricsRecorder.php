<?php

namespace App\Services\Metrics;

use App\Enums\GuardRule;
use App\Enums\QueryRequestStatus;
use App\Enums\StatementType;
use App\Models\QueryRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the counters and histograms behind /metrics (ADR-022).
 *
 * Every public method takes enums or models, never free text, so the label
 * values written are always from a fixed set: no SQL, value, user or team can
 * reach a label. The numbers live in the application database, so every web
 * and worker process adds to the same value.
 *
 * A metric must never break what it measures: a failed write (table missing
 * during a rolling upgrade, a locked SQLite file) is logged and swallowed.
 * Inside a transaction the write waits for the commit instead: a statement
 * that fails there would abort the caller's transaction (PostgreSQL) or be
 * rolled back with it (a MySQL deadlock) even though the error is swallowed,
 * and an event whose transaction rolls back did not happen, so it is not
 * counted.
 */
class MetricsRecorder
{
    private const TABLE = 'metric_samples';

    /** A request entered a status; $kind is read or write. */
    public function requestEntered(QueryRequestStatus $status, ?StatementType $kind): void
    {
        if (! in_array($status, Metric::COUNTED_STATUSES, true) || $kind === null) {
            return;
        }

        $this->guarded(fn () => $this->add(Metric::RequestsTotal->value, ['kind' => $kind->value, 'status' => $status->value]));
    }

    /** An approve or reject decision was recorded on the request. */
    public function approvalDecided(QueryRequest $request): void
    {
        if ($request->reviewed_at === null || $request->created_at === null) {
            return;
        }

        $milliseconds = (int) $request->created_at->diffInMilliseconds($request->reviewed_at, true);

        $this->guarded(fn () => $this->observe(Metric::ApprovalLatencySeconds, [], $milliseconds));
    }

    /** A run settled (completed or failed) after $durationMs on the request's target. */
    public function executionFinished(QueryRequest $request, int $durationMs): void
    {
        $driver = $request->connection?->driver;

        if ($driver === null) {
            return;
        }

        $this->guarded(fn () => $this->observe(Metric::ExecutionDurationSeconds, ['driver' => $driver->value], $durationMs));
    }

    /**
     * The guard refused a statement. Each rule family behind the violations
     * is counted once, however many violations name it.
     *
     * @param  list<string>  $violations
     */
    public function guardRejected(array $violations): void
    {
        foreach (GuardRule::fromViolations($violations) as $rule) {
            $this->guarded(fn () => $this->add(Metric::GuardRejectionsTotal->value, ['rule' => $rule->value]));
        }
    }

    /**
     * Values masked while a result was written, by the match type of the rule.
     *
     * @param  array<string, int>  $tally
     */
    public function maskedValues(array $tally): void
    {
        foreach ($tally as $rule => $count) {
            if ($count > 0 && in_array($rule, Metric::MASK_RULE_LABELS, true)) {
                $this->guarded(fn () => $this->add(Metric::MaskedValuesTotal->value, ['rule' => $rule], $count));
            }
        }
    }

    /** A request was held back because every connection slot was busy. */
    public function slotDeferred(): void
    {
        $this->guarded(fn () => $this->add(Metric::SlotDeferredRequestsTotal->value));
    }

    /**
     * Adds one observation (in milliseconds) to a histogram: the bucket it
     * falls in (the exporter accumulates them) and the running sum.
     *
     * @param  array<string, string>  $labels
     */
    private function observe(Metric $metric, array $labels, int $milliseconds): void
    {
        $milliseconds = max(0, $milliseconds);
        $seconds = $milliseconds / 1000;
        $bucket = '+Inf';

        foreach ($metric->buckets() as $upper) {
            if ($seconds <= (float) $upper) {
                $bucket = $upper;

                break;
            }
        }

        $this->add($metric->value.'_bucket', [...$labels, 'le' => $bucket]);
        $this->add($metric->value.'_sum', $labels, $milliseconds);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function add(string $name, array $labels = [], int $by = 1): void
    {
        ksort($labels);

        $key = json_encode($labels === [] ? new \stdClass : $labels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $row = DB::table(self::TABLE)->where('name', $name)->where('labels', $key);

        // Increment in place; create the row on the first event. Another
        // process may create it first, hence insertOrIgnore and a second
        // increment instead of an insert that could throw.
        if ((clone $row)->increment('value', $by, ['updated_at' => now()]) > 0) {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'name' => $name,
            'labels' => $key,
            'value' => 0,
            'updated_at' => now(),
        ]);

        $row->increment('value', $by, ['updated_at' => now()]);
    }

    private function guarded(callable $write): void
    {
        $run = function () use ($write): void {
            try {
                $write();
            } catch (Throwable $e) {
                Log::warning('Recording a metric failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);

            return;
        }

        $run();
    }
}
