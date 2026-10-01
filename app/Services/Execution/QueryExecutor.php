<?php

namespace App\Services\Execution;

use App\Enums\QueryRequestStatus;
use App\Enums\StatementType;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Masking\Masker;
use App\Services\Sql\SqlInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Runs an approved query request against its target database.
 *
 * Read requests stream row-by-row through a database cursor into an NDJSON
 * file on the configured storage disk, masking each row before it is written,
 * so memory stays constant regardless of result size (ADR-003) and unmasked
 * data never touches the result store.
 */
class QueryExecutor
{
    public function __construct(
        private DynamicConnectionFactory $factory,
        private SqlInspector $inspector,
        private Masker $masker,
    ) {}

    public function execute(QueryRequest $request): void
    {
        // Conditional claim: with duplicate job copies (queue re-reservation,
        // double dispatch) only one worker wins; the rest exit silently.
        $claimed = QueryRequest::whereKey($request->id)
            ->whereIn('status', [QueryRequestStatus::Queued, QueryRequestStatus::Approved])
            ->update([
                'status' => QueryRequestStatus::Running,
                'executed_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $request->refresh();

        $connection = $request->connection;

        // The immutable log records the SQL that actually runs: query_requests
        // rows are mutable and cascade-deletable, the audit trail is not.
        audit()->record('request.execution_started', actor: $request->reviewer, request: $request, sql: $request->sql_prepared);

        $connectionName = $this->factory->configure($connection);
        $start = hrtime(true);
        $settled = false;

        try {
            $statements = $this->inspector->splitStatements($request->sql_prepared);

            if ($statements === []) {
                throw new RuntimeException('No executable statements in prepared SQL.');
            }

            $isRead = $request->type === StatementType::Read && count($statements) === 1;

            $outcome = $isRead
                ? $this->executeRead($request, $connectionName, $statements[0])
                : $this->executeWrite($request, $connectionName, $statements);

            $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

            // Conditional transition: if the query outlived the queue's
            // retry_after window, the job's failed() handler (or another
            // worker) may already have moved the request on. Never overwrite
            // that verdict — the results land only while we still own the run.
            $completed = QueryRequest::whereKey($request->id)
                ->where('status', QueryRequestStatus::Running)
                ->update([
                    ...$outcome,
                    'status' => QueryRequestStatus::Completed,
                    'duration_ms' => $durationMs,
                ]);

            // From here on the request's state is final either way; anything
            // that throws below is bookkeeping, not a query failure.
            $settled = true;

            if ($completed === 0) {
                $this->discardLateCompletion($request, $outcome, $durationMs, $isRead);

                return;
            }

            $request->refresh();

            audit()->record('request.execution_completed', actor: $request->reviewer, request: $request, metadata: [
                'duration_ms' => $request->duration_ms,
                'affected_rows' => $request->affected_rows,
                'result_rows' => $request->result_row_count,
            ]);
        } catch (Throwable $e) {
            // Driver errors can echo the connection host / username / database
            // (fields we encrypt at rest) — redact them before anything
            // user- or auditor-visible; the full exception goes to the log.
            $safeMessage = $this->factory->redactError($e->getMessage(), $connection);

            if ($settled) {
                Log::error('Query execution finished, but recording its outcome failed.', [
                    'query_request_id' => $request->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            Log::warning('Query execution failed.', [
                'query_request_id' => $request->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

            // Same ownership rule as the success path: a late driver error must
            // not overwrite the verdict (and error message) of whoever moved
            // the request out of Running first, e.g. the job's failed() handler.
            $failed = QueryRequest::whereKey($request->id)
                ->where('status', QueryRequestStatus::Running)
                ->update([
                    'status' => QueryRequestStatus::Failed,
                    'duration_ms' => $durationMs,
                    'error_message' => $safeMessage,
                ]);

            if ($failed === 0) {
                $this->recordLateFailure($request, $durationMs, $safeMessage);

                return;
            }

            $request->refresh();

            audit()->record('request.execution_failed', actor: $request->reviewer, request: $request, metadata: [
                'error' => $safeMessage,
            ]);
        } finally {
            $this->factory->purge($connection);
            ApprovalNotifier::requestFinished($request->fresh());
        }
    }

    /**
     * The run finished after the request left the Running state: drop what
     * it produced and leave an audit trace instead of a completion record.
     *
     * A write may already be committed on the target database; rolling it
     * back is not possible from here, so the audit entry says so explicitly.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function discardLateCompletion(QueryRequest $request, array $outcome, int $durationMs, bool $isRead): void
    {
        $resultDiscarded = $isRead;

        if (isset($outcome['result_disk'], $outcome['result_path'])) {
            // A storage failure must not cost the audit trail; the leftover
            // file is unreachable (no result_path) and expires via results:prune.
            // Disks configured with 'throw' => false report a failed delete
            // by returning false; the others throw.
            $exception = null;

            try {
                $resultDiscarded = Storage::disk($outcome['result_disk'])->delete($outcome['result_path']) === true;
            } catch (Throwable $e) {
                $resultDiscarded = false;
                $exception = $e::class;
            }

            if (! $resultDiscarded) {
                Log::warning('Late query result could not be deleted from the result store.', array_filter([
                    'query_request_id' => $request->id,
                    'result_path' => $outcome['result_path'],
                    'exception' => $exception,
                ], fn ($value) => $value !== null));
            }
        }

        $currentStatus = $this->currentStatus($request);

        Log::warning('Query execution finished after the request left the running state; outcome not recorded.', [
            'query_request_id' => $request->id,
            'result_discarded' => $isRead ? $resultDiscarded : null,
            'status' => $currentStatus,
            'duration_ms' => $durationMs,
        ]);

        audit()->record('execution.late_completion', actor: $request->reviewer, request: $request, metadata: array_filter([
            'duration_ms' => $durationMs,
            'status' => $currentStatus,
            'result_discarded' => $isRead ? $resultDiscarded : null,
            'write_committed' => $isRead ? null : true,
            'affected_rows' => $outcome['affected_rows'] ?? null,
        ], fn ($value) => $value !== null));
    }

    /**
     * The run errored after the request left the Running state: keep the
     * existing verdict and record the late error as a late completion.
     */
    private function recordLateFailure(QueryRequest $request, int $durationMs, string $safeMessage): void
    {
        audit()->record('execution.late_completion', actor: $request->reviewer, request: $request, metadata: [
            'duration_ms' => $durationMs,
            'status' => $this->currentStatus($request),
            'failed' => true,
            'error' => $safeMessage,
        ]);
    }

    /**
     * The request's status as stored right now, as its raw string value.
     */
    private function currentStatus(QueryRequest $request): ?string
    {
        $status = QueryRequest::whereKey($request->id)->value('status');

        return $status instanceof QueryRequestStatus ? $status->value : $status;
    }

    /**
     * Stream a read query into the result store.
     *
     * @return array<string, mixed> result attributes for the request row,
     *                              persisted only if the run still owns it
     */
    private function executeRead(QueryRequest $request, string $connectionName, string $sql): array
    {
        $disk = $this->resultDisk();
        $path = sprintf('results/%d/%d.ndjson', $request->team_id, $request->id);
        $rules = $this->masker->rulesFor($request->connection);

        $temp = tmpfile() ?: throw new RuntimeException('Could not create temporary result file.');

        // Absolute row ceiling, enforced regardless of what the SQL says:
        // even a query that dodges the LIMIT guard (dialect quirks, SHOW,
        // EXPLAIN, ...) can never stream more than the hard cap.
        $hardLimit = max(1, (int) config('queryproxy.select_hard_limit', 10000));

        try {
            $columns = [];
            $rowCount = 0;
            $truncated = false;

            foreach (DB::connection($connectionName)->cursor($sql) as $row) {
                if ($rowCount >= $hardLimit) {
                    $truncated = true;

                    break;
                }

                $row = (array) $row;

                if ($rowCount === 0) {
                    $columns = array_keys($row);
                }

                $masked = $this->masker->maskRow($row, $rules);

                fwrite($temp, json_encode(array_values($masked), JSON_UNESCAPED_UNICODE, 512)."\n");
                $rowCount++;
            }

            rewind($temp);
            Storage::disk($disk)->put($path, $temp);

            return [
                'result_disk' => $disk,
                'result_path' => $path,
                'result_row_count' => $rowCount,
                'result_truncated' => $truncated,
                'result_columns' => json_encode($columns),
            ];
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }
    }

    /**
     * Resolve the result disk, refusing any disk the web server publishes.
     *
     * Result files are only ever meant to be reachable through
     * ResultDownloadController, which enforces QueryRequestPolicy. A publicly
     * visible disk (the shipped `public` disk, or any disk configured with
     * `visibility => public`) serves `results/{team}/{id}.ndjson` over a
     * guessable URL through `storage:link`, which bypasses that policy and
     * exposes every team's masked-or-not rows by walking sequential ids.
     * Refusing to write is the only safe option: the request fails loudly
     * instead of silently publishing.
     *
     * @throws RuntimeException when the configured disk is publicly visible
     */
    private function resultDisk(): string
    {
        $disk = (string) config('queryproxy.result_disk', 'local');

        if ($disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new RuntimeException(sprintf(
                'Refusing to store query results on the "%s" filesystem disk because it is publicly visible. '.
                'Point QUERYPROXY_RESULT_DISK at a private disk (for example "local") and re-run the request.',
                $disk,
            ));
        }

        return $disk;
    }

    /**
     * @param  list<string>  $statements
     * @return array<string, mixed> result attributes for the request row
     */
    private function executeWrite(QueryRequest $request, string $connectionName, array $statements): array
    {
        $connection = DB::connection($connectionName);

        $run = function () use ($connection, $statements): int {
            $affected = 0;

            foreach ($statements as $statement) {
                $affected += $connection->affectingStatement($statement);
            }

            return $affected;
        };

        // Multi-statement requests always run atomically; single writes run as-is.
        $affected = count($statements) > 1 || $request->is_transaction
            ? $connection->transaction($run)
            : $run();

        return ['affected_rows' => $affected];
    }
}
