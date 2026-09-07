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

        try {
            $statements = $this->inspector->splitStatements($request->sql_prepared);

            if ($statements === []) {
                throw new RuntimeException('No executable statements in prepared SQL.');
            }

            if ($request->type === StatementType::Read && count($statements) === 1) {
                $this->executeRead($request, $connectionName, $statements[0]);
            } else {
                $this->executeWrite($request, $connectionName, $statements);
            }

            $request->update([
                'status' => QueryRequestStatus::Completed,
                'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000),
            ]);

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

            Log::warning('Query execution failed.', [
                'query_request_id' => $request->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $request->update([
                'status' => QueryRequestStatus::Failed,
                'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000),
                'error_message' => $safeMessage,
            ]);

            audit()->record('request.execution_failed', actor: $request->reviewer, request: $request, metadata: [
                'error' => $safeMessage,
            ]);
        } finally {
            $this->factory->purge($connection);
            ApprovalNotifier::requestFinished($request->fresh());
        }
    }

    private function executeRead(QueryRequest $request, string $connectionName, string $sql): void
    {
        $disk = config('queryproxy.result_disk', 'local');
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

            $request->update([
                'result_disk' => $disk,
                'result_path' => $path,
                'result_row_count' => $rowCount,
                'result_truncated' => $truncated,
                'result_columns' => $columns,
            ]);
        } finally {
            if (is_resource($temp)) {
                fclose($temp);
            }
        }
    }

    /**
     * @param  list<string>  $statements
     */
    private function executeWrite(QueryRequest $request, string $connectionName, array $statements): void
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

        $request->update(['affected_rows' => $affected]);
    }
}
