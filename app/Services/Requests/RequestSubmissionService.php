<?php

namespace App\Services\Requests;

use App\Enums\AuditEvent;
use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Masking\SensitiveFilterDetector;
use App\Services\Metrics\MetricsRecorder;
use App\Services\Sql\SqlInspector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The single entry point for submitting a query request, used by the web UI
 * and the REST API alike, so every channel enforces identical rules: the
 * connection policy, the SQL guard, the sensitive-filter warning, the audit
 * record and the approver notifications. Approval decisions live in
 * ApprovalService.
 */
class RequestSubmissionService
{
    public function __construct(
        private readonly SqlInspector $inspector,
        private readonly SensitiveFilterDetector $sensitiveFilters,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @param  string|null  $reason  Free-text note for the approver.
     * @param  string  $channel  Where the submission came from; recorded in the audit entry.
     *
     * @throws AuthorizationException when the user may not use the connection, or is suspended or deleted
     * @throws RequestSubmissionException when the SQL guard refuses the statement
     */
    public function submit(
        User $user,
        Connection $connection,
        string $sql,
        ?string $reason,
        string $channel = 'web',
        ?string $title = null,
    ): QueryRequest {
        Gate::forUser($user)->authorize('use', $connection);

        $result = $this->inspector->inspect($sql, $connection->driver);

        if (! $result->passes()) {
            // Counted on submission only, never when previewing: the studio's
            // Validate button also inspects SQL, may be pressed any number of
            // times, and is not a refused submission.
            $this->metrics->guardRejected($result->violations);

            throw new RequestSubmissionException($result->violations);
        }

        // A warning for the approver only: a failure here must never stop the submission.
        try {
            $sensitiveFilterColumns = $this->sensitiveFilters->detect($result->preparedSql(), $connection);
        } catch (Throwable $e) {
            report($e);
            $sensitiveFilterColumns = null;
        }

        $note = trim((string) $reason);

        $request = DB::transaction(function () use ($user, $connection, $sql, $title, $note, $result, $sensitiveFilterColumns, $channel) {
            // Same row lock as UserLifecycle::delete: a user deleted or
            // suspended since the request began cannot leave a pending request.
            $owner = User::whereKey($user->id)->lockForUpdate()->first();

            if ($owner === null || $owner->isSuspended()) {
                throw new AuthorizationException('This account may not submit requests.');
            }

            $request = QueryRequest::create([
                'team_id' => $connection->team_id,
                'connection_id' => $connection->id,
                'user_id' => $user->id,
                'title' => $title ?: null,
                'note' => $note !== '' ? $note : null,
                'sql_original' => $sql,
                'sql_prepared' => $result->preparedSql(),
                'statement_count' => count($result->statements),
                'is_transaction' => $result->isTransaction,
                'is_ddl' => $result->hasDdl(),
                'type' => $result->type(),
                'status' => QueryRequestStatus::Pending,
                'sensitive_filter_columns' => $sensitiveFilterColumns,
            ]);

            audit()->record(AuditEvent::RequestSubmitted, actor: $user, request: $request, sql: $request->sql_prepared, metadata: [
                'type' => $request->type->value,
                'statements' => $request->statement_count,
                'channel' => $channel,
            ]);

            return $request;
        }, attempts: 3);

        $this->metrics->requestEntered(QueryRequestStatus::Pending, $request->type);

        ApprovalNotifier::requestSubmitted($request);

        return $request;
    }
}
