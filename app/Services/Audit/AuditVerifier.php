<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

class AuditVerifier
{
    public function __construct(private readonly AuditChain $chain) {}

    /**
     * Walk the chain from the first record. Stops at the first broken one.
     *
     * The lock row is read before the walk. Writers may keep appending while
     * the walk runs; those records are checked like any other, but the tail
     * check only holds the walk to what had been written when it began.
     *
     * @return array{ok: bool, checked: int, head: string, broken_id: int|null, reason: string|null}
     */
    public function verify(): array
    {
        $lock = $this->readLock();
        $sequence = $lock === null ? 0 : (int) $lock->sequence;

        $expectedPrevious = AuditChain::GENESIS;
        $checked = 0;
        $atSequence = null;
        $broken = null;

        DB::table('audit_logs')->orderBy('id')->lazyById(500)->each(function ($row) use (&$expectedPrevious, &$checked, &$atSequence, &$broken, $sequence) {
            $reason = $this->inspect($row, $expectedPrevious);

            if ($reason !== null) {
                $broken = ['id' => (int) $row->id, 'reason' => $reason];

                return false;
            }

            $expectedPrevious = $row->hash;
            $checked++;

            if ($checked === $sequence) {
                $atSequence = $row->hash;
            }

            return true;
        });

        $broken ??= $this->inspectTail($lock, $sequence, $checked, $atSequence);

        return [
            'ok' => $broken === null,
            'checked' => $checked,
            'head' => $expectedPrevious,
            'broken_id' => $broken['id'] ?? null,
            'reason' => $broken['reason'] ?? null,
        ];
    }

    /**
     * The lock row as it is now, or null when it does not exist.
     */
    protected function readLock(): ?object
    {
        return DB::table(AuditChain::LOCK_TABLE)->where('id', 1)->first();
    }

    /**
     * A walk that finds every record intact still cannot see records that
     * are missing from the end. The lock row counts every chained insert and
     * holds the head hash (both roll back with the insert), so a cut-off
     * tail, an emptied table or a TRUNCATE (which no trigger stops on MySQL
     * and SQL Server) shows up as a mismatch with the walked chain.
     *
     * $sequence and the lock's head belong to the moment before the walk: the
     * walk must have reached that many records, and the record at that
     * position must be the recorded head. Records appended after that moment
     * are beyond both numbers and were checked by the walk itself.
     *
     * @return array{id: int|null, reason: string}|null
     */
    private function inspectTail(?object $lock, int $sequence, int $checked, ?string $atSequence): ?array
    {
        if ($lock === null) {
            return $checked === 0 ? null : ['id' => null, 'reason' => 'the chain lock row is missing, the chain length cannot be confirmed'];
        }

        if ($checked < $sequence) {
            return ['id' => null, 'reason' => "the chain holds {$checked} records but {$sequence} were written (records were removed from the end or the table was truncated)"];
        }

        $recorded = is_string($lock->head_hash) && $lock->head_hash !== '' ? $lock->head_hash : AuditChain::GENESIS;

        if ($sequence > 0 && $recorded !== $atSequence) {
            return ['id' => null, 'reason' => 'the last record is not the recorded chain head (the tail was replaced)'];
        }

        return null;
    }

    private function inspect(object $row, string $expectedPrevious): ?string
    {
        if (! is_string($row->hash) || $row->hash === '' || ! is_string($row->prev_hash) || $row->prev_hash === '') {
            return 'record carries no hash (written outside the chain)';
        }

        if ($row->prev_hash !== $expectedPrevious) {
            return 'prev_hash does not match the preceding record (a record was removed, inserted or reordered)';
        }

        if ($this->chain->hash($row->prev_hash, (array) $row) !== $row->hash) {
            return 'hash does not match the record contents (the record was modified)';
        }

        return null;
    }
}
