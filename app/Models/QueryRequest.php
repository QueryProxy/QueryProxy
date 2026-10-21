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
    'error_message',
])]
class QueryRequest extends Model
{
    use HasFactory;

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
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function chatApprovalTokens(): HasMany
    {
        return $this->hasMany(ChatApprovalToken::class);
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

    public function hasResult(): bool
    {
        return $this->status === QueryRequestStatus::Completed && $this->result_path !== null;
    }
}
