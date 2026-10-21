<?php

namespace App\Models;

use App\Services\Audit\AuditChain;
use App\Services\Sql\SqlRedactor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Insert and chain in one transaction: the chain lock taken by
     * AuditChain::seal() is held until the record is committed, so records
     * are appended in a single order (ADR-020). The chain head moves inside
     * seal(), in this same transaction.
     */
    protected function performInsert(Builder $query)
    {
        return $this->getConnection()->transaction(fn () => parent::performInsert($query));
    }

    /**
     * saveOrIgnore() would skip the insert silently after the chain lock and
     * head were already moved. Audit records are never written that way.
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy)
    {
        throw new RuntimeException('Audit logs cannot be inserted with saveOrIgnore().');
    }

    /**
     * Seal the record at the last possible moment: parent::performInsert()
     * has fired `creating` (listeners may still change fields) and set the
     * timestamps by now, and this is the attribute set that is inserted, so
     * the hash always covers exactly what is stored. Still inside the
     * transaction opened by performInsert().
     */
    protected function getAttributesForInsert()
    {
        // Whole seconds only: the hashed value must survive any column type.
        $this->setAttribute('created_at', Carbon::parse($this->getAttribute('created_at'))->startOfSecond());

        app(AuditChain::class)->seal($this);

        return parent::getAttributesForInsert();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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
