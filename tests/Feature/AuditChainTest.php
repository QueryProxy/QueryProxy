<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTriggers;
use App\Services\Audit\AuditVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    // DDL may not roll back on every driver: always leave the triggers in place.
    $triggers = new AuditTriggers;
    $triggers->drop();
    $triggers->install();
});

test('records are chained from the genesis value', function () {
    $first = audit()->record('test.first', metadata: ['b' => 1, 'a' => ['y' => 2, 'x' => 3]]);
    $second = audit()->record('test.second', sql: 'SELECT 1');

    expect($first->prev_hash)->toBe(AuditChain::GENESIS)
        ->and($first->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash);
});

test('the canonical form is stable', function () {
    $chain = new AuditChain;

    $row = [
        'team_id' => '3', 'user_id' => null, 'action' => 'x.y', 'query_request_id' => null,
        'connection_id' => 7, 'sql' => 'SELECT 1', 'metadata' => '{"b":1,"a":{"z":true,"c":[2,1]}}',
        'ip' => '10.0.0.1', 'created_at' => '2026-10-20 12:30:00',
    ];

    expect($chain->canonical($row))->toBe(
        '{"team_id":3,"user_id":null,"action":"x.y","query_request_id":null,"connection_id":7,'
        .'"sql":"SELECT 1","metadata":{"a":{"c":[2,1],"z":true},"b":1},"ip":"10.0.0.1","created_at":"2026-10-20 12:30:00"}'
    )->and($chain->canonical([...$row, 'metadata' => '{"a":{"c":[2,1],"z":true},"b":1}']))
        ->toBe($chain->canonical($row))
        ->and($chain->canonical([...$row, 'created_at' => '2026-10-20 12:30:00.000']))
        ->toBe($chain->canonical($row))
        ->and($chain->canonical([...$row, 'created_at' => '2026-10-20 12:30:00.999']))
        ->toBe($chain->canonical($row));
});

test('direct AuditLog::create is chained too', function () {
    $a = AuditLog::create(['action' => 'test.a']);
    $b = AuditLog::create(['action' => 'test.b']);

    expect($b->prev_hash)->toBe($a->hash)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('audit:verify passes on an untouched chain', function () {
    foreach (range(1, 25) as $i) {
        audit()->record('test.event', metadata: ['i' => $i]);
    }

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Audit chain intact: 25 records verified.')
        ->assertExitCode(0);
});

test('audit:verify passes on an empty table', function () {
    $this->artisan('audit:verify')->assertExitCode(0);
});

test('the triggers reject raw UPDATE and DELETE', function () {
    $log = audit()->record('test.event');

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']))
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and(fn () => DB::table('audit_logs')->delete())
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and(AuditLog::count())->toBe(1);
});

test('audit:verify names the modified record', function () {
    audit()->record('test.one');
    $target = audit()->record('test.two', sql: 'SELECT 1');
    audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $target->id)->update(['sql' => 'DROP TABLE users']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain("record id {$target->id}: hash does not match the record contents")
        ->assertExitCode(1);
});

test('audit:verify names the record after a deleted one', function () {
    audit()->record('test.one');
    $removed = audit()->record('test.two');
    $next = audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $removed->id)->delete();

    $this->artisan('audit:verify')
        ->expectsOutputToContain("record id {$next->id}")
        ->assertExitCode(1);
});

test('audit:verify flags a record written outside the chain', function () {
    audit()->record('test.one');

    DB::table('audit_logs')->insert(['action' => 'test.raw', 'created_at' => now()]);
    $raw = DB::table('audit_logs')->where('action', 'test.raw')->value('id');

    $this->artisan('audit:verify')
        ->expectsOutputToContain("record id {$raw}")
        ->assertExitCode(1);
});

test('the database refuses a fork of the chain', function () {
    $first = audit()->record('test.one');
    audit()->record('test.two');

    expect(fn () => DB::table('audit_logs')->insert([
        'action' => 'test.fork', 'created_at' => now(),
        'prev_hash' => $first->prev_hash, 'hash' => str_repeat('a', 64),
    ]))->toThrow(QueryException::class);
});

test('backfill chains existing records from the genesis value', function () {
    audit()->record('test.one', metadata: ['k' => 'v']);
    audit()->record('test.two');
    audit()->record('test.three', sql: 'SELECT 1');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->update(['prev_hash' => null, 'hash' => null]);

    $this->artisan('audit:verify')->assertExitCode(1);

    expect((new AuditChain)->backfill())->toBe(3);

    $this->artisan('audit:verify')->assertExitCode(0);

    expect(DB::table('audit_logs')->orderBy('id')->value('prev_hash'))->toBe(AuditChain::GENESIS);
});

test('the Eloquent guard still rejects updates and deletes', function () {
    $log = audit()->record('test.event');

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class);
});

test('audit:checkpoint records the chain head and skips when nothing changed', function () {
    $last = audit()->record('test.event');

    $this->artisan('audit:checkpoint')->assertExitCode(0);

    $checkpoint = AuditLog::where('action', AuditEvent::AuditCheckpoint->value)->sole();

    expect($checkpoint->metadata)->toMatchArray(['last_id' => $last->id, 'last_hash' => $last->hash, 'records' => 1]);

    $this->artisan('audit:checkpoint')->assertExitCode(0);

    expect(AuditLog::where('action', AuditEvent::AuditCheckpoint->value)->count())->toBe(1);

    $this->artisan('audit:verify')->assertExitCode(0);
});

test('created_at is stored in whole seconds so the hashed value is the stored one', function () {
    // SQL Server `datetime` rounds fractions to 1/300 s; .999 would become
    // the next second there. Truncating before the hash makes it irrelevant.
    $log = (new AuditLog(['action' => 'test.fraction']))->forceFill(['created_at' => '2026-10-20 12:30:00.999']);
    $log->save();

    $stored = DB::table('audit_logs')->where('id', $log->id)->value('created_at');

    expect($log->created_at->microsecond)->toBe(0)
        ->and(substr((string) $stored, 0, 19))->toBe('2026-10-20 12:30:00')
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('an audit write inside an outer transaction chains correctly', function () {
    $before = audit()->record('test.before');

    DB::transaction(function () use ($before, &$inside) {
        // Reads first, as a real business transaction would, so its read
        // view is already fixed when the audit record is written.
        DB::table('teams')->count();
        AuditLog::count();

        $inside = audit()->record('test.inside');
        $after = audit()->record('test.after');

        expect($inside->prev_hash)->toBe($before->hash)
            ->and($after->prev_hash)->toBe($inside->hash);
    });

    expect(AuditLog::count())->toBe(3)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('a rolled back transaction leaves no gap in the chain', function () {
    $first = audit()->record('test.first');

    try {
        DB::transaction(function () {
            audit()->record('test.rolled_back');

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
        // expected
    }

    $second = audit()->record('test.second');

    expect($second->prev_hash)->toBe($first->hash)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('a creating listener that changes a field is still covered by the hash', function () {
    // The record is sealed after `creating`, so a listener (now or in the
    // future) cannot make the stored row differ from the hashed one.
    AuditLog::creating(fn (AuditLog $log) => $log->sql = 'SELECT changed');

    $log = AuditLog::create(['action' => 'test.listener', 'sql' => 'SELECT original']);

    expect(DB::table('audit_logs')->where('id', $log->id)->value('sql'))->toBe('SELECT changed')
        ->and($this->artisan('audit:verify')->run())->toBe(0);

    AuditLog::flushEventListeners();
    (new ReflectionMethod(AuditLog::class, 'booted'))->invoke(null);
});

test('audit:verify catches a cut-off tail', function () {
    audit()->record('test.one');
    audit()->record('test.two');
    $last = audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $last->id)->delete();

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Audit chain broken at the end of the chain: the chain holds 2 records but 3 were written')
        ->assertExitCode(1);
});

test('audit:verify catches an emptied table', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->delete();

    $this->artisan('audit:verify')
        ->expectsOutputToContain('the chain holds 0 records but 2 were written')
        ->assertExitCode(1);
});

test('audit:verify catches a replaced tail', function () {
    audit()->record('test.one');
    $last = audit()->record('test.two');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $last->id)->delete();
    // Same length, a different last record, self-consistent hash.
    $forged = ['action' => 'test.forged', 'created_at' => '2026-10-20 12:00:00', 'prev_hash' => $last->prev_hash];
    $forged['hash'] = (new AuditChain)->hash($last->prev_hash, $forged);
    DB::table('audit_logs')->insert($forged);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('the last record is not the recorded chain head')
        ->assertExitCode(1);
});

test('audit:verify does not flag a record appended while it runs', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    // A writer commits right after the verifier read the lock row.
    $verifier = new class(new AuditChain) extends AuditVerifier
    {
        protected function readLock(): ?object
        {
            $lock = parent::readLock();
            audit()->record('test.during-walk');

            return $lock;
        }
    };

    $result = $verifier->verify();

    expect($result['ok'])->toBeTrue()
        ->and($result['checked'])->toBe(3)
        ->and($result['reason'])->toBeNull();
});

test('audit:verify still catches a cut-off tail when a record was appended during the walk', function () {
    audit()->record('test.one');
    $last = audit()->record('test.two');

    $verifier = new class(new AuditChain, $last->id) extends AuditVerifier
    {
        public function __construct(AuditChain $chain, private readonly int $cutId)
        {
            parent::__construct($chain);
        }

        protected function readLock(): ?object
        {
            $lock = parent::readLock();

            // Tamper after the read, then a legitimate writer appends.
            (new AuditTriggers)->drop();
            DB::table('audit_logs')->where('id', $this->cutId)->delete();
            (new AuditTriggers)->install();

            return $lock;
        }
    };

    $result = $verifier->verify();

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain('the chain holds 1 records but 2 were written');
});

test('the chain head moves in seal(), whatever a created listener does', function () {
    $nested = false;
    AuditLog::created(function () use (&$nested) {
        if (! $nested) {
            $nested = true;
            audit()->record('test.nested-in-created');
        }
    });

    $outer = audit()->record('test.outer');

    $lock = DB::table(AuditChain::LOCK_TABLE)->where('id', 1)->first();
    $last = AuditLog::query()->orderByDesc('id')->first();

    expect(AuditLog::count())->toBe(2)
        ->and((int) $lock->sequence)->toBe(2)
        ->and($lock->head_hash)->toBe($last->hash)
        ->and($this->artisan('audit:verify')->run())->toBe(0);

    AuditLog::flushEventListeners();
    (new ReflectionMethod(AuditLog::class, 'booted'))->invoke(null);
});

test('a failed insert leaves the chain head and counter where they were', function () {
    $first = audit()->record('test.first');

    AuditLog::created(fn () => throw new RuntimeException('listener failed'));

    expect(fn () => audit()->record('test.second'))->toThrow(RuntimeException::class, 'listener failed');

    $lock = DB::table(AuditChain::LOCK_TABLE)->where('id', 1)->first();

    expect((int) $lock->sequence)->toBe(1)
        ->and($lock->head_hash)->toBe($first->hash);

    AuditLog::flushEventListeners();
    (new ReflectionMethod(AuditLog::class, 'booted'))->invoke(null);

    expect($this->artisan('audit:verify')->run())->toBe(0);
});

test('saveOrIgnore is refused for audit logs', function () {
    expect(fn () => (new AuditLog(['action' => 'test.ignore']))->saveOrIgnore())
        ->toThrow(RuntimeException::class, 'saveOrIgnore');

    expect(AuditLog::count())->toBe(0);
});

test('the chain migration can be re-run after a partial failure', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    $migration = require database_path('migrations/2026_10_20_000001_add_hash_chain_to_audit_logs_table.php');

    // Re-running up() on a fully migrated database must neither fail nor
    // change the chain.
    $head = DB::table(AuditChain::LOCK_TABLE)->value('head_hash');
    $migration->up();

    expect(DB::table(AuditChain::LOCK_TABLE)->value('head_hash'))->toBe($head)
        ->and(DB::table(AuditChain::LOCK_TABLE)->value('sequence'))->toBe(2)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});
