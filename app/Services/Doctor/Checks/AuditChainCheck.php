<?php

namespace App\Services\Doctor\Checks;

use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditVerifier;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\CheckStatus;
use App\Services\Doctor\NeedsDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick mode of audit:verify (ADR-020): re-hashes the newest WINDOW records,
 * checks that they link to each other and that the last one is the head the
 * chain lock row recorded. It catches a tampered or replaced tail in
 * constant time; the full walk (audit:verify, or doctor --full-audit)
 * reads the whole table.
 */
class AuditChainCheck extends BaseCheck implements NeedsDatabase
{
    /** Newest records re-hashed in quick mode. */
    public const WINDOW = 200;

    /** Reads before a quick-mode failure is reported (see runQuick()). */
    public const ATTEMPTS = 3;

    private const RETRY_PAUSE_MICROSECONDS = 50_000;

    public function __construct(
        private readonly AuditChain $chain,
        private readonly AuditVerifier $verifier,
        private readonly bool $full = false,
    ) {}

    public function name(): string
    {
        return 'audit_chain';
    }

    public function run(): CheckResult
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasTable(AuditChain::LOCK_TABLE)) {
            return $this->fail('The audit tables are missing; run: php artisan migrate --force');
        }

        return $this->full ? $this->runFull() : $this->runQuick();
    }

    private function runFull(): CheckResult
    {
        $result = $this->verifier->verify();

        if (! $result['ok']) {
            $where = $result['broken_id'] === null ? 'at the end of the chain' : "at record id {$result['broken_id']}";

            return $this->fail("Audit chain broken {$where}: {$result['reason']}. Run: php artisan audit:verify");
        }

        return $this->ok("Full verification: {$result['checked']} records intact.");
    }

    /**
     * The newest records and the lock row are two reads with no snapshot, so a
     * record committed in between makes a healthy chain look cut off. A real
     * break is still there on the next read; a race is not. Look up to
     * ATTEMPTS times before reporting a failure.
     */
    private function runQuick(): CheckResult
    {
        $result = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $result = $this->inspectNewest();

            if ($result->status !== CheckStatus::Fail) {
                return $result;
            }

            if ($attempt < self::ATTEMPTS) {
                usleep(self::RETRY_PAUSE_MICROSECONDS);
            }
        }

        return $result;
    }

    protected function readLock(): ?object
    {
        return DB::table(AuditChain::LOCK_TABLE)->where('id', 1)->first();
    }

    private function inspectNewest(): CheckResult
    {
        // Rows first, lock second: a record written in between only raises the
        // lock's sequence, which the retry above absorbs.
        $rows = DB::table('audit_logs')->orderByDesc('id')->limit(self::WINDOW + 1)->get()->reverse()->values();
        $lock = $this->readLock();

        if ($rows->isEmpty()) {
            return $lock !== null && (int) $lock->sequence > 0
                ? $this->fail("The audit log is empty but {$lock->sequence} records were written; records were removed. Run: php artisan audit:verify")
                : $this->ok('The audit log is empty.');
        }

        if ($lock === null) {
            return $this->fail('The chain lock row is missing, so the chain length cannot be confirmed. Run: php artisan audit:verify');
        }

        $previous = null;

        foreach ($rows as $row) {
            if (! is_string($row->hash) || $row->hash === '' || ! is_string($row->prev_hash) || $row->prev_hash === '') {
                return $this->fail("Record {$row->id} carries no hash (written outside the chain). Run: php artisan audit:verify");
            }

            if ($previous !== null && $row->prev_hash !== $previous->hash) {
                return $this->fail("Record {$row->id} does not link to the record before it (a record was removed, inserted or reordered). Run: php artisan audit:verify");
            }

            if ($this->chain->hash($row->prev_hash, (array) $row) !== $row->hash) {
                return $this->fail("Record {$row->id} does not match its hash (the record was modified). Run: php artisan audit:verify");
            }

            $previous = $row;
        }

        // The whole chain fits the window: its start and its length are known too.
        if ($rows->count() <= self::WINDOW) {
            if ($rows->first()->prev_hash !== AuditChain::GENESIS) {
                return $this->fail("The first record ({$rows->first()->id}) does not start the chain; older records were removed. Run: php artisan audit:verify");
            }

            if ($rows->count() !== (int) $lock->sequence) {
                return $this->fail("The chain holds {$rows->count()} records but {$lock->sequence} were written (records were removed or are being added). Run: php artisan audit:verify");
            }
        }

        if (! is_string($lock->head_hash) || $lock->head_hash !== $previous->hash) {
            return $this->fail('The newest record is not the recorded chain head (the tail was replaced or removed). Run: php artisan audit:verify');
        }

        return $this->ok('Newest '.$rows->count().' records intact and the head matches (full walk: php artisan audit:verify).');
    }
}
