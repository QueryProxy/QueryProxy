<?php

/*
 * Read requests on a real server (Faz 01): a statement that changes data
 * never takes effect through a read request. Runs through
 * QueryExecutor::execute with a stored Connection, as a queue worker would,
 * and looks at the request row, the result file and the tables.
 *
 * These tests cover the whole path, so the refusal they see comes from the
 * guard: executeRead re-classifies the statement and rejects a write before it
 * reaches the server. The database layer (READ ONLY session and transaction)
 * is covered separately and without the guard in ReadOnlySessionTest. SQL
 * Server has no engine-level READ ONLY mode, there the read-only login is the
 * protection.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Services\Connections\DynamicConnectionFactory;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    $this->it = IntegrationTarget::start();
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->stop();
    }
});

/** The SQL that returns the name of the login the session runs as. */
function itWhoAmI(): string
{
    return match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => 'SELECT current_user AS who',
        DbDriver::Sqlsrv => 'SELECT SUSER_SNAME() AS who',
        default => "SELECT SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AS who",
    };
}

test('a read request streams its rows into the result file', function () {
    $request = $this->it->execute($this->it->request($this->it->connection(), 'SELECT id, label FROM {items} ORDER BY id'));

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->result_row_count)->toBe(5)
        ->and($request->result_truncated)->toBeFalse()
        ->and($request->backend_pid)->toBeNull()
        ->and($this->it->resultRows($request))->toBe([['1', 'one'], ['2', 'two'], ['3', 'three'], ['4', 'four'], ['5', 'five']]);
});

test('a read request that changes data is refused by the executor and changes nothing', function (string $sql) {
    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server has no engine-level READ ONLY mode; its protection is the read-only login (see the read identity tests).');
    }

    $request = $this->it->execute($this->it->request($this->it->connection(), $sql));

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('submitted as a read request')
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5])
        ->and($this->it->itemLabel(1))->toBe('one');
})->with([
    'insert' => ["INSERT INTO {items} (id, label) VALUES (99, 'x')"],
    'update' => ["UPDATE {items} SET label = 'changed'"],
    'delete' => ['DELETE FROM {items}'],
]);

// DDL is the case the READ ONLY transaction alone did not cover on MySQL
// (implicit commit); what counts is whether anything changed on the server,
// so that is asserted first. The server-side refusal itself is tested in
// ReadOnlySessionTest.
test('a DDL statement in a read request is refused and creates or empties nothing', function (string $sql) {
    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server has no engine-level READ ONLY mode; its protection is the read-only login (see the read identity tests).');
    }

    $request = $this->it->execute($this->it->request($this->it->connection(), $sql));

    expect($this->it->tableExists('created'))->toBeFalse('the DDL statement ran outside the READ ONLY transaction: the table now exists (request status '.$request->status->value.', message: '.$request->error_message.')')
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5], 'the DDL statement ran outside the READ ONLY transaction: the rows are gone (request status '.$request->status->value.', message: '.$request->error_message.')')
        ->and($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('submitted as a read request');
})->with([
    'create table' => ['CREATE TABLE {created} (id integer)'],
    'truncate' => ['TRUNCATE TABLE {items}'],
]);

test('a read request with a PostgreSQL sequence side effect or an attempt to leave read-only mode is refused by the executor', function (string $sql, string $message) {
    if (IntegrationTarget::driver() !== DbDriver::Pgsql) {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $sequence = $this->it->table('seq');
    $this->it->admin()->statement("CREATE SEQUENCE {$sequence}");

    try {
        $request = $this->it->execute($this->it->request($this->it->connection(), str_replace('{seq}', $sequence, $sql)));

        // Refused for being a change in a read request, not for some other reason.
        expect($request->status)->toBe(QueryRequestStatus::Failed)
            ->and($request->error_message)->toMatch($message);

        // The sequence did not move: its next value is still the first.
        expect((int) $this->it->admin()->selectOne("SELECT nextval('{$sequence}') AS n")->n)->toBe(1);
    } finally {
        $this->it->admin()->statement("DROP SEQUENCE {$sequence}");
    }
})->with([
    'nextval' => ["SELECT nextval('{seq}')", '/submitted as a read request/'],
    // The guard counts both as writes; PostgreSQL's own refusal of them is in ReadOnlySessionTest.
    'leave read-only mode' => ["SELECT set_config('transaction_read_only', 'off', false)", '/submitted as a read request|read-write mode/i'],
]);

test('with a read-only login configured, reads run as that login and the main login does not', function () {
    $connection = $this->it->connection(withReader: true);
    $reader = $this->it->reader();

    $read = $this->it->execute($this->it->request($connection, itWhoAmI()));

    expect($read->status)->toBe(QueryRequestStatus::Completed)
        ->and($this->it->resultRows($read))->toBe([[$reader['username']]]);

    // The main identity is untouched: a write request runs as it and commits.
    $write = $this->it->execute($this->it->request($connection, "INSERT INTO {items} (id, label) VALUES (6, 'six')", 'write'));

    expect($write->status)->toBe(QueryRequestStatus::Completed)
        ->and($write->affected_rows)->toBe(1)
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5, 6]);
});

test('a statement that changes data is refused to the read-only login on every driver', function () {
    $connection = $this->it->connection(withReader: true);

    $request = $this->it->execute($this->it->request($connection, "UPDATE {items} SET label = 'changed' WHERE id = 1"));

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($this->it->itemLabel(1))->toBe('one');
});

// Nothing the read set up on the session may reach the write that follows on
// the same connection (no read login): a READ ONLY session setting that
// outlived the request would make the write fail on MySQL / MariaDB.
test('a write after a read on a connection without a read login still commits', function () {
    $connection = $this->it->connection();

    $read = $this->it->execute($this->it->request($connection, 'SELECT id FROM {items} ORDER BY id'));
    $write = $this->it->execute($this->it->request($connection, "INSERT INTO {items} (id, label) VALUES (6, 'six')", 'write'));
    $again = $this->it->execute($this->it->request($connection, 'SELECT id FROM {items} ORDER BY id'));

    expect($read->status)->toBe(QueryRequestStatus::Completed)
        ->and($write->status)->toBe(QueryRequestStatus::Completed)
        ->and($write->affected_rows)->toBe(1)
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5, 6])
        ->and($again->status)->toBe(QueryRequestStatus::Completed)
        ->and($again->result_row_count)->toBe(6);
});

test('the connection test probes the read-only login too', function () {
    $factory = app(DynamicConnectionFactory::class);

    expect($factory->test($this->it->connection(withReader: true))['ok'])->toBeTrue();

    $broken = $this->it->connection(withReader: true);
    $broken->update(['read_password' => 'not-the-password']);

    $result = $factory->test($broken->fresh());

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toStartWith('Read credentials: ');
});
