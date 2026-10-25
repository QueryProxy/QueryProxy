<?php

/*
 * The database layer of the read-only protection on a real server (Faz 01,
 * review of Faz 15): TargetSession::beginReadOnly() and endReadOnly() are driven
 * directly, WITHOUT QueryExecutor and therefore without the SqlInspector
 * re-check in executeRead. Whatever the guard would have caught, the server
 * itself has to refuse here, and nothing may change.
 *
 * The case that matters is MySQL: it commits implicitly before DDL, which ends
 * the READ ONLY transaction, so "SET TRANSACTION READ ONLY" (next transaction
 * only) left the DDL statement running outside it. The session-wide setting
 * makes the server refuse it, and endReadOnly() fails closed when the
 * transaction was closed under it.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\DbDriver;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\TargetSession;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server has no engine-level READ ONLY mode; its protection is the read-only login (see the read identity tests).');
    }

    $this->it = IntegrationTarget::start();
    $this->target = $this->it->connection();
});

afterEach(function () {
    if (isset($this->target)) {
        app(DynamicConnectionFactory::class)->purge($this->target);
    }

    if (isset($this->it)) {
        $this->it->stop();
    }
});

/** A fresh session on the target, configured the way the executor does it. */
function itTargetSession(object $test): DatabaseConnection
{
    return DB::connection(app(DynamicConnectionFactory::class)->configure($test->target));
}

/** The server's own answer to "is this session read-only", normalised to a bool. */
function itSessionIsReadOnly(DatabaseConnection $db): bool
{
    $value = match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => $db->selectOne("SELECT current_setting('transaction_read_only') AS ro")->ro,
        DbDriver::Mariadb => $db->selectOne('SELECT @@SESSION.tx_read_only AS ro')->ro,
        default => $db->selectOne('SELECT @@SESSION.transaction_read_only AS ro')->ro,
    };

    return in_array(strtolower((string) $value), ['1', 'on', 'true'], true);
}

/** Run $sql on $db and return what the server answered with, or null when it accepted it. */
function itAttempt(DatabaseConnection $db, string $sql): ?Throwable
{
    try {
        $db->unprepared($sql);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('the server reports the read session as read-only', function () {
    $db = itTargetSession($this);
    $session = app(TargetSession::class);

    $session->beginReadOnly($db, IntegrationTarget::driver());

    expect(itSessionIsReadOnly($db))->toBeTrue();

    $session->endReadOnly($db, commit: true);

    // MySQL and MariaDB keep it for the whole session, so a statement that
    // runs after the transaction ended is read-only too. PostgreSQL's
    // characteristic belongs to the transaction.
    if (IntegrationTarget::isMysqlFamily()) {
        expect(itSessionIsReadOnly($db))->toBeTrue();
    }
});

test('the server refuses DDL handed straight to a read session and nothing changes', function (string $sql) {
    $db = itTargetSession($this);
    $session = app(TargetSession::class);

    $session->beginReadOnly($db, IntegrationTarget::driver());

    $error = itAttempt($db, $this->it->sql($sql));

    $session->endReadOnly($db, commit: false);

    expect($error)->not->toBeNull('the server accepted the statement in a read-only session')
        ->and(TargetSession::isReadOnlyViolation($error))->toBeTrue('refused, but not as a read-only violation: '.$error?->getMessage())
        ->and($this->it->tableExists('created'))->toBeFalse('the table was created')
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5]);
})->with([
    'create table' => ['CREATE TABLE {created} (id integer)'],
    'truncate' => ['TRUNCATE TABLE {items}'],
    'drop table' => ['DROP TABLE {items}'],
    'alter table' => ['ALTER TABLE {items} ADD COLUMN extra integer'],
]);

test('data statements handed straight to a read session are refused too', function (string $sql) {
    $db = itTargetSession($this);
    $session = app(TargetSession::class);

    $session->beginReadOnly($db, IntegrationTarget::driver());

    $error = itAttempt($db, $this->it->sql($sql));

    $session->endReadOnly($db, commit: false);

    expect($error)->not->toBeNull()
        ->and(TargetSession::isReadOnlyViolation($error))->toBeTrue($error?->getMessage())
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5])
        ->and($this->it->itemLabel(1))->toBe('one');
})->with([
    'insert' => ["INSERT INTO {items} (id, label) VALUES (99, 'x')"],
    'update' => ["UPDATE {items} SET label = 'changed'"],
    'delete' => ['DELETE FROM {items}'],
]);

// A statement that ends the transaction cannot be produced by DDL any more
// (the session is read-only, so DDL is refused before it commits anything), so
// the closing COMMIT is sent by hand: it is what an implicit commit does to the
// transaction. PostgreSQL has no implicit commit, so nothing to cover there.
test('a read whose transaction was closed under it is refused, and the session stays read-only', function () {
    if (! IntegrationTarget::isMysqlFamily()) {
        $this->markTestSkipped('Implicit commits are a MySQL / MariaDB behaviour.');
    }

    $db = itTargetSession($this);
    $session = app(TargetSession::class);

    $session->beginReadOnly($db, IntegrationTarget::driver());
    $db->getPdo()->exec('COMMIT');

    expect(fn () => $session->endReadOnly($db, commit: true))
        ->toThrow(RuntimeException::class, 'ended by the statement itself')
        ->and($db->transactionLevel())->toBe(0);

    // Outside any transaction (autocommit) the session still refuses writes.
    $error = itAttempt($db, $this->it->sql('CREATE TABLE {created} (id integer)'));

    expect($error)->not->toBeNull('the server accepted DDL on the session after its transaction was closed')
        ->and(TargetSession::isReadOnlyViolation($error))->toBeTrue($error?->getMessage())
        ->and($this->it->tableExists('created'))->toBeFalse()
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5]);
});

// What the guard counts as a write but only the server can really stop: a
// sequence moving and a switch out of read-only mode. PostgreSQL only.
test('PostgreSQL refuses a sequence side effect and a switch out of read-only mode handed straight to a read session', function (string $sql) {
    if (IntegrationTarget::driver() !== DbDriver::Pgsql) {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $sequence = $this->it->table('seq');
    $this->it->admin()->statement("CREATE SEQUENCE {$sequence}");

    try {
        $db = itTargetSession($this);
        $session = app(TargetSession::class);

        $session->beginReadOnly($db, IntegrationTarget::driver());

        $error = itAttempt($db, str_replace('{seq}', $sequence, $sql));

        $session->endReadOnly($db, commit: false);

        expect($error)->not->toBeNull('the server accepted the statement in a read-only session')
            ->and($error->getMessage())->toMatch('/read-only|read-write mode/i');

        // The sequence did not move: its next value is still the first.
        expect((int) $this->it->admin()->selectOne("SELECT nextval('{$sequence}') AS n")->n)->toBe(1);
    } finally {
        $this->it->admin()->statement("DROP SEQUENCE {$sequence}");
    }
})->with([
    'nextval' => ["SELECT nextval('{seq}')"],
    'leave read-only mode' => ["SELECT set_config('transaction_read_only', 'off', false)"],
]);
