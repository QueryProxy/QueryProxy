<?php

/*
 * Live audit chain checks (ADR-020) on a real application database.
 *
 * The Feature suite runs the chain on SQLite. This file proves the parts that
 * are driver specific on the real thing: the migration and its triggers, the
 * rejection of UPDATE/DELETE, audit:verify, whole-second timestamps, the chain
 * head under an outer transaction (REPEATABLE READ on MySQL/MariaDB), and
 * concurrent writers.
 *
 * Runs only when QUERYPROXY_LIVE_APPDB_HOST is set. DRIVER is one of pgsql,
 * mysql, mariadb, sqlsrv:
 *
 *   QUERYPROXY_LIVE_APPDB_DRIVER=mysql QUERYPROXY_LIVE_APPDB_HOST=127.0.0.1 \
 *   QUERYPROXY_LIVE_APPDB_PORT=33306 QUERYPROXY_LIVE_APPDB_DATABASE=queryproxy_test \
 *   QUERYPROXY_LIVE_APPDB_USERNAME=root QUERYPROXY_LIVE_APPDB_PASSWORD=... \
 *   ./vendor/bin/pest --group=live-appdb
 *
 * The database name must end in "_test" (the tests drop the audit tables); set
 * QUERYPROXY_LIVE_APPDB_ALLOW_ANY_DATABASE=1 to run against e.g. SQL Server
 * "master".
 *
 * The user needs CREATE/DROP on the schema and the TRIGGER privilege (plus
 * SUPER on MySQL/MariaDB with binary logging and no
 * log_bin_trust_function_creators).
 */

use App\Models\AuditLog;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Live\LiveAppDb;

/**
 * True when the server refuses a write to a row that changed after the read
 * view was fixed (MariaDB 11.8+ with innodb_snapshot_isolation=ON): the outer
 * transaction is then expected to need its second attempt. Servers without
 * the variable (MySQL, PostgreSQL, SQL Server, older MariaDB) are not.
 */
function liveSnapshotIsolationOn(): bool
{
    try {
        $value = DB::selectOne('SELECT @@innodb_snapshot_isolation AS value')->value ?? null;
    } catch (Throwable) {
        return false;
    }

    return in_array(strtoupper((string) $value), ['1', 'ON'], true);
}

beforeEach(function () {
    if (! LiveAppDb::configured()) {
        LiveAppDb::failIfRequired();
        $this->markTestSkipped('Set '.LiveAppDb::ENV_PREFIX.'HOST (and _DRIVER, _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live audit chain tests.');
    }

    $this->originalDefault = DB::getDefaultConnection();

    LiveAppDb::start();
});

afterEach(function () {
    if (LiveAppDb::configured()) {
        LiveAppDb::stop($this->originalDefault);
    }
});

test('the migration installs triggers that reject UPDATE and DELETE', function () {
    $log = audit()->record('test.event', sql: 'SELECT 1');

    expect(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']))
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and(fn () => DB::table('audit_logs')->where('id', $log->id)->delete())
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and(DB::table('audit_logs')->count())->toBe(1)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('audit:verify names a modified record', function () {
    audit()->record('test.one');
    $target = audit()->record('test.two', sql: 'SELECT 1');
    audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $target->id)->update(['sql' => 'DROP TABLE users']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain("record id {$target->id}: hash does not match the record contents")
        ->assertExitCode(1);
});

test('audit:verify catches a deleted record and a cut-off tail', function () {
    audit()->record('test.one');
    $middle = audit()->record('test.two');
    $next = audit()->record('test.three');

    (new AuditTriggers)->drop();
    DB::table('audit_logs')->where('id', $middle->id)->delete();

    $this->artisan('audit:verify')->expectsOutputToContain("record id {$next->id}")->assertExitCode(1);

    DB::table('audit_logs')->where('id', $next->id)->delete();
    DB::table('audit_logs')->where('id', '>', 0)->update(['prev_hash' => AuditChain::GENESIS]);
    // Only the first record is left and it is intact; two were removed.
    $this->artisan('audit:verify')
        ->expectsOutputToContain('the chain holds 1 records but 3 were written')
        ->assertExitCode(1);
});

test('TRUNCATE is rejected where a trigger can stop it and caught by audit:verify where not', function () {
    audit()->record('test.one');
    audit()->record('test.two');

    $driver = LiveAppDb::driver();

    if ($driver === 'pgsql') {
        expect(fn () => DB::statement('TRUNCATE TABLE audit_logs'))
            ->toThrow(QueryException::class, AuditTriggers::MESSAGE)
            ->and(DB::table('audit_logs')->count())->toBe(2);

        return;
    }

    // MySQL, MariaDB and SQL Server fire no trigger on TRUNCATE.
    DB::statement('TRUNCATE TABLE audit_logs');

    $this->artisan('audit:verify')
        ->expectsOutputToContain('the chain holds 0 records but 2 were written')
        ->assertExitCode(1);
});

test('created_at fractions never break the hash', function () {
    // .999 would round to the next second on SQL Server `datetime`.
    foreach (range(0, 40) as $i) {
        $log = (new AuditLog(['action' => 'test.fraction']))
            ->forceFill(['created_at' => sprintf('2026-10-20 12:30:%02d.%03d', $i % 60, ($i * 37 + 999) % 1000)]);
        $log->save();
    }

    $extreme = (new AuditLog(['action' => 'test.fraction']))->forceFill(['created_at' => '2026-10-20 12:30:59.999']);
    $extreme->save();

    expect($this->artisan('audit:verify')->run())->toBe(0);
});

test('the migration chains records that existed before it', function () {
    $migration = LiveAppDb::chainMigration();
    $migration->down();

    foreach (range(1, 7) as $i) {
        DB::table('audit_logs')->insert([
            'action' => 'legacy.event', 'sql' => "SELECT {$i}", 'metadata' => json_encode(['i' => $i]),
            'ip' => '10.0.0.1', 'created_at' => '2026-10-19 10:00:0'.$i,
        ]);
    }

    $migration->up();

    expect($this->artisan('audit:verify')->expectsOutputToContain('Audit chain intact: 7 records verified.')->run())->toBe(0);

    audit()->record('test.after');

    expect($this->artisan('audit:verify')->run())->toBe(0)
        // pdo_sqlsrv returns a bigint as a string.
        ->and((int) DB::table('audit_chain_lock')->value('sequence'))->toBe(8);
});

test('the migration can be re-run after a partial failure', function () {
    audit()->record('test.one');

    $migration = LiveAppDb::chainMigration();

    // Between two steps, as after a failed CREATE TRIGGER on MySQL.
    (new AuditTriggers)->drop();
    $migration->up();
    $migration->up();

    expect(fn () => DB::table('audit_logs')->update(['action' => 'x']))->toThrow(QueryException::class, AuditTriggers::MESSAGE)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('an audit write inside an outer transaction sees the latest chain head', function () {
    // Under MySQL/MariaDB REPEATABLE READ the outer transaction fixes its
    // read view at its first read; a second connection then commits a record.
    // The audit write of the outer transaction must still chain after it.
    //
    // MariaDB 11.8 (innodb_snapshot_isolation=ON) refuses that write with
    // error 1020 instead, because the chain lock row changed after the read
    // view was fixed. The outer transaction is therefore opened with attempts
    // (the rule for every transaction that writes an audit record): the second
    // attempt reads afresh and chains after the other record. Elsewhere the
    // first attempt already succeeds.
    audit()->record('test.before');

    $attempts = 0;
    $other = null;
    $inside = null;

    DB::transaction(function () use (&$attempts, &$other, &$inside) {
        $attempts++;

        AuditLog::count();

        // Another process commits only during the first attempt.
        if ($other === null) {
            $other = (new AuditLog(['action' => 'test.other']))->setConnection(LiveAppDb::SECOND_CONNECTION);
            $other->save();
        }

        $inside = audit()->record('test.inside');
    }, attempts: 3);

    expect($attempts)->toBe(liveSnapshotIsolationOn() ? 2 : 1)
        ->and($inside->prev_hash)->toBe($other->hash)
        ->and(DB::table('audit_logs')->count())->toBe(3)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('an outer transaction that reads, loses the chain to another process and then writes audit succeeds on retry', function () {
    // The scenario of the MariaDB 11.8 regression: the outer transaction reads
    // (fixing its snapshot), a separate process advances the chain and commits,
    // then the outer transaction writes its audit record. Only the retry of the
    // outermost transaction, which starts a fresh snapshot, can succeed.
    audit()->record('test.before');

    $attempts = 0;
    $inside = null;

    DB::transaction(function () use (&$attempts, &$inside) {
        $attempts++;

        AuditLog::count();

        if ($attempts === 1) {
            $writer = new Process([PHP_BINARY, base_path('tests/Live/audit_writer.php'), '1', 'between'], base_path());
            $writer->setTimeout(60)->run();

            expect($writer->getExitCode())->toBe(0, $writer->getErrorOutput().$writer->getOutput());
        }

        $inside = audit()->record('test.inside');
    }, attempts: 3);

    $between = AuditLog::where('action', 'test.concurrent')->firstOrFail();

    expect($attempts)->toBe(liveSnapshotIsolationOn() ? 2 : 1)
        ->and($inside->prev_hash)->toBe($between->hash)
        ->and(DB::table('audit_logs')->count())->toBe(3)
        ->and((int) DB::table('audit_chain_lock')->value('sequence'))->toBe(3)
        ->and($this->artisan('audit:verify')->expectsOutputToContain('3 records verified')->run())->toBe(0);
});

test('concurrent writers cannot fork the chain', function () {
    $writers = [];

    foreach (range(1, 4) as $n) {
        $writers[] = new Process([PHP_BINARY, base_path('tests/Live/audit_writer.php'), '25', "w{$n}"], base_path());
    }

    foreach ($writers as $writer) {
        $writer->setTimeout(120)->start();
    }

    foreach ($writers as $writer) {
        $writer->wait();

        expect($writer->getExitCode())->toBe(0, $writer->getErrorOutput().$writer->getOutput());
    }

    expect(DB::table('audit_logs')->count())->toBe(100)
        ->and((int) DB::table('audit_chain_lock')->value('sequence'))->toBe(100)
        ->and($this->artisan('audit:verify')->expectsOutputToContain('100 records verified')->run())->toBe(0);
});
