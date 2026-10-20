<?php

/*
 * Live PostgreSQL checks for the read-only session and the DB-side timeouts
 * that TargetSession applies to read requests.
 *
 * The unit suite proves which statements are issued per driver; this file
 * proves that a real server enforces them: a write attempt inside the
 * READ ONLY transaction fails with "read-only transaction", a slow statement
 * is cut by statement_timeout and a held row lock is given up on by
 * lock_timeout.
 *
 * Runs only when QUERYPROXY_LIVE_PGSQL_HOST is set (group "live-pgsql"); see
 * PgsqlGuardTest.php for the docker compose setup.
 */

use App\Enums\DbDriver;
use App\Services\Execution\TargetSession;
use Tests\Live\LivePgsql;

beforeEach(function () {
    if (! LivePgsql::configured()) {
        $this->markTestSkipped('Set '.LivePgsql::ENV_PREFIX.'HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live PostgreSQL read-only tests.');
    }

    $this->live = LivePgsql::start();
    $this->session = app(TargetSession::class);
    $this->live->db()->statement('CREATE SEQUENCE qp_seq');
});

afterEach(function () {
    if (isset($this->live)) {
        $this->live->stop();
    }
});

test('a write attempt inside the read-only transaction fails on the server', function (string $sql) {
    $db = $this->live->db();

    $this->session->beginReadOnly($db, DbDriver::Pgsql);

    try {
        expect(fn () => $db->unprepared($sql))->toThrow(function (Throwable $e) {
            expect(TargetSession::isReadOnlyViolation($e))->toBeTrue()
                ->and(strtolower($e->getMessage()))->toContain('read-only transaction');
        });
    } finally {
        $this->session->endReadOnly($db, commit: false);
    }

    expect($db->table('items')->count())->toBe(5);
})->with([
    'insert' => ["INSERT INTO items (id, label) VALUES (99, 'x')"],
    'update' => ["UPDATE items SET label = 'x'"],
    'delete' => ['DELETE FROM items'],
    'create table' => ['CREATE TABLE should_not_exist (id integer)'],
    'sequence side effect' => ["SELECT nextval('qp_seq')"],
]);

test('a plain read works inside the read-only transaction', function () {
    $db = $this->live->db();

    $this->session->beginReadOnly($db, DbDriver::Pgsql);

    try {
        expect($db->selectOne('SELECT count(*) AS n FROM items')->n)->toBe(5);
    } finally {
        $this->session->endReadOnly($db, commit: true);
    }
});

test('the statement timeout cuts a slow statement', function () {
    config(['queryproxy.execution_timeout' => 6]); // statement timeout = 1 s

    $db = $this->live->db();
    $this->session->beginReadOnly($db, DbDriver::Pgsql);

    try {
        $started = microtime(true);

        expect(fn () => $db->select('SELECT pg_sleep(10)'))
            ->toThrow(fn (Throwable $e) => expect(strtolower($e->getMessage()))->toContain('statement timeout'));

        expect(microtime(true) - $started)->toBeLessThan(5.0);
    } finally {
        $this->session->endReadOnly($db, commit: false);
    }
});

test('the lock timeout gives up on a row locked by another session', function () {
    config(['queryproxy.lock_timeout' => 1]);

    $other = $this->live->otherSession();
    $other->beginTransaction();
    $other->select('SELECT * FROM items WHERE id = 1 FOR UPDATE');

    $db = $this->live->db();
    $this->session->beginReadOnly($db, DbDriver::Pgsql);

    try {
        // FOR UPDATE is refused by the read-only transaction before it can
        // wait, so wait on a lock the read-only session is allowed to take.
        $other->statement('LOCK TABLE items IN ACCESS EXCLUSIVE MODE');

        $started = microtime(true);

        expect(fn () => $db->select('SELECT * FROM items'))
            ->toThrow(fn (Throwable $e) => expect(strtolower($e->getMessage()))->toContain('lock timeout'));

        expect(microtime(true) - $started)->toBeLessThan(5.0);
    } finally {
        $this->session->endReadOnly($db, commit: false);
        $other->rollBack();
    }
});
