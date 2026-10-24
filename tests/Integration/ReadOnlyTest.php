<?php

/*
 * Read requests on a real server (Faz 01): the executor opens a READ ONLY
 * transaction (PostgreSQL, MySQL, MariaDB), so a statement that changes data
 * fails on the server even though it reached the executor as a "read"; SQL
 * Server has no such mode, there the read-only login is the protection. Runs
 * through QueryExecutor::execute with a stored Connection, as a queue worker
 * would, and looks at the request row, the result file and the tables.
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

test('a statement that changes data fails inside the read-only transaction and changes nothing', function (string $sql) {
    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server has no engine-level READ ONLY mode; its protection is the read-only login (see the read identity tests).');
    }

    $request = $this->it->execute($this->it->request($this->it->connection(), $sql));

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('read-only database session')
        ->and($this->it->itemIds())->toBe([1, 2, 3, 4, 5])
        ->and($this->it->itemLabel(1))->toBe('one');
})->with([
    'insert' => ["INSERT INTO {items} (id, label) VALUES (99, 'x')"],
    'update' => ["UPDATE {items} SET label = 'changed'"],
    'delete' => ['DELETE FROM {items}'],
    'create table' => ['CREATE TABLE {created} (id integer)'],
]);

test('PostgreSQL refuses a sequence side effect and an attempt to leave read-only mode', function (string $sql, string $message) {
    if (IntegrationTarget::driver() !== DbDriver::Pgsql) {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $sequence = $this->it->table('seq');
    $this->it->admin()->statement("CREATE SEQUENCE {$sequence}");

    try {
        $request = $this->it->execute($this->it->request($this->it->connection(), str_replace('{seq}', $sequence, $sql)));

        // Refused for being a change in a read-only session, not for some other reason.
        expect($request->status)->toBe(QueryRequestStatus::Failed)
            ->and($request->error_message)->toMatch($message);

        // The sequence did not move: its next value is still the first.
        expect((int) $this->it->admin()->selectOne("SELECT nextval('{$sequence}') AS n")->n)->toBe(1);
    } finally {
        $this->it->admin()->statement("DROP SEQUENCE {$sequence}");
    }
})->with([
    'nextval' => ["SELECT nextval('{seq}')", '/read-only database session/'],
    // PostgreSQL's own words: "transaction read-write mode must be set before any query" (or, inside a subtransaction, "cannot set transaction read-write mode inside a read-only transaction", which the executor words as above).
    'leave read-only mode' => ["SELECT set_config('transaction_read_only', 'off', false)", '/read-only database session|read-write mode/i'],
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

test('the connection test probes the read-only login too', function () {
    $factory = app(DynamicConnectionFactory::class);

    expect($factory->test($this->it->connection(withReader: true))['ok'])->toBeTrue();

    $broken = $this->it->connection(withReader: true);
    $broken->update(['read_password' => 'not-the-password']);

    $result = $factory->test($broken->fresh());

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toStartWith('Read credentials: ');
});
