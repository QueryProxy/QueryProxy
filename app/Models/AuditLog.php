<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'team_id', 'user_id', 'action', 'query_request_id',
    'connection_id', 'sql', 'metadata', 'ip',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Audit logs are immutable: they can only ever be inserted.
        static::updating(fn () => throw new RuntimeException('Audit logs are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Audit logs are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }
}
