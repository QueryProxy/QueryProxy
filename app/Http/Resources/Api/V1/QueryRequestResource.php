<?php

namespace App\Http\Resources\Api\V1;

use App\Models\QueryRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A query request as the REST API shows it. What the caller may read of the
 * SQL follows the web UI (ADR-009): the requester, the team DBA and an admin
 * get the text as written, everyone else who may view the request gets only
 * the redacted form with the literals replaced. Column names count as result
 * data like the rows (a column named after an expression carries its literals,
 * and `SELECT *` exposes the target schema), so they follow `viewResult`, as in
 * the web UI where only the result viewer shows them.
 *
 * @mixin QueryRequest
 */
class QueryRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $rawSql = $user !== null && $user->can('viewRawSql', $this->resource);
        $resultData = $user !== null && $user->can('viewResult', $this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'type' => $this->type->value,
            'connection_id' => $this->connection_id,
            'team_id' => $this->team_id,
            'requested_by' => $this->user_id,
            'title' => $this->title,
            'note' => $this->note,
            'sql_original' => $rawSql ? $this->sql_original : null,
            'sql_prepared' => $rawSql ? $this->sql_prepared : null,
            'sql_redacted' => $rawSql ? null : $this->redactedSql(),
            'statement_count' => $this->statement_count,
            'is_transaction' => $this->is_transaction,
            'is_ddl' => $this->is_ddl,
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_channel' => $this->review_channel,
            'rejection_reason' => $this->rejection_reason,
            'executed_at' => $this->executed_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'affected_rows' => $this->affected_rows,
            'error_message' => $this->error_message,
            'result' => [
                'available' => $this->hasResult(),
                'row_count' => $this->result_row_count,
                'truncated' => $resultData ? $this->result_truncated : null,
                'columns' => $resultData && is_array($this->result_columns) ? $this->result_columns : null,
            ],
            'web_url' => route('requests.show', $this->resource),
        ];
    }
}
