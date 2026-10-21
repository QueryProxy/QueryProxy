<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hash chain over the audit log (ADR-020).
 *
 * hash = SHA-256(prev_hash || canonical_json(record)). The canonical form
 * below is part of the stored format: changing it needs a new chain
 * version, never a silent rewrite.
 *
 * created_at is hashed as `Y-m-d H:i:s` in UTC. AuditLog truncates the
 * stored value to the second, so every driver stores exactly what was
 * hashed (SQL Server `datetime` would round fractions to 1/300 s). On MySQL
 * and MariaDB the column is a TIMESTAMP, converted through the session time
 * zone, so config/database.php pins both connections to `timezone` =>
 * '+00:00'; without it a daylight-saving shift could read an hour off and
 * fail audit:verify. SQLite stores the string, PostgreSQL `timestamp(0)
 * without time zone` and SQL Server `datetime` do no zone conversion.
 */
class AuditChain
{
    /** prev_hash of the first record. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public const LOCK_TABLE = 'audit_chain_lock';

    /**
     * Fields covered by the hash, in canonical order. `id` is left out on
     * purpose: it is unknown until the insert; the chain order is enforced
     * through prev_hash instead.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'team_id', 'user_id', 'action', 'query_request_id',
        'connection_id', 'sql', 'metadata', 'ip', 'created_at',
    ];

    private const INTEGER_FIELDS = ['team_id', 'user_id', 'query_request_id', 'connection_id'];

    /**
     * Link a record to the end of the chain. Must run inside the transaction
     * that inserts the record: the lock is held until that transaction ends,
     * so concurrent writers cannot fork the chain.
     *
     * The chain head lives on the lock row and is read with a locking read.
     * A plain SELECT on audit_logs would not do: under MySQL/MariaDB
     * REPEATABLE READ a transaction that already read something (an audit
     * write inside a larger business transaction) keeps its old snapshot and
     * would see a stale head. A locking read always sees the latest committed
     * row, so the head is correct whatever the isolation level.
     *
     * The head moves to the new record right here, in the same transaction:
     * if the insert fails or a later listener throws, the move rolls back
     * with it, and the order of model events does not matter.
     */
    public function seal(AuditLog $log): void
    {
        $previous = $this->lock($log->getConnection())->head_hash;
        $previous = is_string($previous) && $previous !== '' ? $previous : self::GENESIS;

        $log->setAttribute('prev_hash', $previous);

        $hash = $this->hash($previous, $log->getAttributes());
        $log->setAttribute('hash', $hash);

        $log->getConnection()->table(self::LOCK_TABLE)->where('id', 1)->update(['head_hash' => $hash]);
    }

    /**
     * Take the chain mutex and return the lock row. The increment is a write
     * to a single row: it serializes writers on every supported driver (row
     * lock on pgsql/mysql/sqlsrv, write lock on sqlite) even on an empty
     * table, where SELECT ... FOR UPDATE on audit_logs would lock nothing. It
     * also counts the chained records, which audit:verify checks against.
     * The locking read that follows returns the current head.
     */
    private function lock(ConnectionInterface $connection): object
    {
        $touched = $connection->table(self::LOCK_TABLE)->where('id', 1)->increment('sequence');

        if ($touched === 0) {
            $connection->table(self::LOCK_TABLE)->insertOrIgnore(['id' => 1, 'sequence' => 0]);
            $connection->table(self::LOCK_TABLE)->where('id', 1)->increment('sequence');
        }

        return $connection->table(self::LOCK_TABLE)->where('id', 1)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $row  raw record fields (metadata as a JSON string)
     */
    public function hash(string $previous, array $row): string
    {
        return hash('sha256', $previous.$this->canonical($row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function canonical(array $row): string
    {
        $canonical = [];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;

            $canonical[$field] = match (true) {
                $value === null => null,
                in_array($field, self::INTEGER_FIELDS, true) => (int) $value,
                $field === 'metadata' => $this->normalizeMetadata($value),
                $field === 'created_at' => Carbon::parse($value)->format('Y-m-d H:i:s'),
                default => (string) $value,
            };
        }

        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Chain every record from the genesis value. Used by the migration that
     * introduces the chain and by tests; the table triggers must be absent.
     * The lock row is set to the chained count and head, so audit:verify can
     * tell a cut-off tail from an untouched chain.
     *
     * @return int number of records chained
     */
    public function backfill(?ConnectionInterface $connection = null, string $table = 'audit_logs'): int
    {
        $connection ??= DB::connection();
        $previous = self::GENESIS;
        $count = 0;

        $connection->table($table)->orderBy('id')->chunkById(500, function ($rows) use ($connection, $table, &$previous, &$count) {
            foreach ($rows as $row) {
                $hash = $this->hash($previous, (array) $row);

                $connection->table($table)->where('id', $row->id)->update([
                    'prev_hash' => $previous,
                    'hash' => $hash,
                ]);

                $previous = $hash;
                $count++;
            }
        });

        if ($count > 0 && $connection->getSchemaBuilder()->hasTable(self::LOCK_TABLE)) {
            $connection->table(self::LOCK_TABLE)->updateOrInsert(['id' => 1], ['sequence' => $count, 'head_hash' => $previous]);
        }

        return $count;
    }

    private function normalizeMetadata(mixed $value): mixed
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return $this->sortKeys($decoded);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
