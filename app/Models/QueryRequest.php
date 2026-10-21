<?php

namespace App\Models;

use App\Enums\QueryRequestStatus;
use App\Enums\StatementType;
use App\Services\Sql\SqlRedactor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id', 'connection_id', 'user_id', 'title', 'sql_original', 'sql_prepared',
    'statement_count', 'is_transaction', 'is_ddl', 'type', 'status', 'note',
    'reviewed_by', 'reviewed_at', 'rejection_reason', 'review_channel',
    'reminded_at', 'chat_renewed_at',
    'executed_at', 'duration_ms', 'affected_rows',
    'result_disk', 'result_path', 'result_row_count', 'result_truncated', 'result_columns',
    'error_message', 'sensitive_filter_columns',
])]
class QueryRequest extends Model
{
    use HasFactory;

    /** Audit action written when a request is held back because the connection's slots are busy. */
    public const SLOT_DEFERRED_ACTION = 'request.execution_deferred';

    protected function casts(): array
    {
        return [
            'status' => QueryRequestStatus::class,
            'type' => StatementType::class,
            'is_transaction' => 'boolean',
            'is_ddl' => 'boolean',
            'result_truncated' => 'boolean',
            'reviewed_at' => 'datetime',
            'reminded_at' => 'datetime',
            'chat_renewed_at' => 'datetime',
            'executed_at' => 'datetime',
            'result_columns' => 'array',
            'sensitive_filter_columns' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function requester(): BelongsTo
    {
        // Soft-deleted users stay visible: the request history must keep its names.
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function chatApprovalTokens(): HasMany
    {
        return $this->hasMany(ChatApprovalToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', QueryRequestStatus::Pending);
    }

    /**
     * The SQL with its literals replaced by `?`, for viewers who may not see the
     * raw text (see QueryRequestPolicy::viewRawSql). Null when it cannot be
     * redacted safely: the caller shows no SQL then, never the raw statement.
     *
     * @param  'sql_prepared'|'sql_original'  $column
     */
    public function redactedSql(string $column = 'sql_prepared'): ?string
    {
        return app(SqlRedactor::class)->redact((string) $this->getAttribute($column), $this->getRelationValue('connection')?->driver);
    }

    public function isPending(): bool
    {
        return $this->status === QueryRequestStatus::Pending;
    }

    /** Adds `is_waiting_for_slot` (a deferral entry exists) to each row, so a list can show the badge without a query per row; isWaitingForSlot() still requires the Queued status. */
    public function scopeWithSlotWait(Builder $query): Builder
    {
        return $query->withExists(['auditLogs as is_waiting_for_slot' => fn (Builder $logs) => $logs
            ->where('action', self::SLOT_DEFERRED_ACTION)]);
    }

    /**
     * True while the request sits in the queue because every connection slot
     * was busy. Derived from the deferral audit entry (no status of its own):
     * it clears when the request starts running or reaches a final state.
     */
    public function isWaitingForSlot(): bool
    {
        if ($this->status !== QueryRequestStatus::Queued) {
            return false;
        }

        if (array_key_exists('is_waiting_for_slot', $this->attributes)) {
            return (bool) $this->attributes['is_waiting_for_slot'];
        }

        return $this->auditLogs()->where('action', self::SLOT_DEFERRED_ACTION)->exists();
    }

    public function hasResult(): bool
    {
        return $this->status === QueryRequestStatus::Completed && $this->result_path !== null;
    }

    /**
     * Masked columns this request filters on; empty when none or not analysed.
     *
     * @return list<string>
     */
    public function sensitiveFilterColumns(): array
    {
        return array_values($this->sensitive_filter_columns ?? []);
    }
}
