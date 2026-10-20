<?php

namespace App\Models;

use App\Services\Sql\SqlRedactor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * The statement with its literals replaced by `?`, or null when there is no
     * SQL or it could not be redacted (fail-closed: nothing is shown then).
     *
     * Computed on read, never stored: the audit table is append-only, so rows
     * written before redaction existed are covered the same way, no migration
     * or hash-chain field is needed, and a better redactor applies retroactively.
     * Every audit surface (view, CSV, SIEM export) shows this, never `sql`.
     */
    protected function sqlRedacted(): Attribute
    {
        return Attribute::get(function () {
            if ($this->sql === null || $this->sql === '') {
                return null;
            }

            // getAttribute: `$this->connection` would be Eloquent's own DB-connection-name property.
            return app(SqlRedactor::class)->redact($this->sql, $this->getAttribute('connection')?->driver);
        })->shouldCache();
    }

    /** The row has SQL, but the redactor could not make it safe to show. */
    public function sqlIsHidden(): bool
    {
        return $this->sql !== null && $this->sql !== '' && $this->sql_redacted === null;
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
