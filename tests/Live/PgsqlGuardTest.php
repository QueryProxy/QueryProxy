<?php

/*
 * Live PostgreSQL checks for SqlInspector.
 *
 * The unit suite proves what the guard decides; this file proves that the
 * decision is right on a real server: what the guard lets through as a read
 * really is harmless, and what it refuses or counts as a write really has the
 * side effect it is guarded for. Every test asserts the guard's verdict first
 * and then runs the SQL to observe its effect.
 *
 * Runs only when QUERYPROXY_LIVE_PGSQL_HOST is set (group "live-pgsql"):
 *
 *   docker compose -f docker-compose.test.yml up -d
 *   QUERYPROXY_LIVE_PGSQL_HOST=127.0.0.1 QUERYPROXY_LIVE_PGSQL_PORT=55432 \
 *   QUERYPROXY_LIVE_PGSQL_DATABASE=queryproxy_test \
 *   QUERYPROXY_LIVE_PGSQL_USERNAME=queryproxy_test \
 *   QUERYPROXY_LIVE_PGSQL_PASSWORD=queryproxy_test \
 *   ./vendor/bin/pest --group=live-pgsql
 *
 * Tests that need a superuser (most guarded settings are superuser-only, and
 * so is dblink) are skipped on a server where the test role is not one.
 */

use App\Enums\StatementType;
use Illuminate\Database\QueryException;
use Tests\Live\LivePgsql;

beforeEach(function () {
    if (! LivePgsql::configured()) {
        $this->markTestSkipped('Set '.LivePgsql::ENV_PREFIX.'HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live PostgreSQL guard tests.');
    }

    $this->live = LivePgsql::start();
});

afterEach(function () {
    if (isset($this->live)) {
        $this->live->stop();
    }
});

function requireLiveSuperuser(LivePgsql $live): void
{
    if (! $live->isSuperuser()) {
        test()->markTestSkipped('The live PostgreSQL role is not a superuser.');
    }
}

function expectLiveRefused(LivePgsql $live, string $sql, string $needle): void
{
    $result = $live->guard($sql);

    expect($result->passes())->toBeFalse("the guard let through: {$sql}")
        ->and(implode("\n", $result->violations))->toContain($needle);
}

function expectLiveWrite(LivePgsql $live, string $sql): void
{
    $result = $live->guard($sql);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Write);
}

/**
 * The settings Faz 02 added to the guard, as name => [value to SET, value
 * PostgreSQL then reports, needs a superuser].
 */
const GUARDED_PGSQL_SETTINGS = [
    'session_preload_libraries' => ["'auto_explain'", 'auto_explain', true],
    'local_preload_libraries' => ["'auto_explain'", 'auto_explain', false],
    'dynamic_library_path' => ["'/tmp:\$libdir'", '/tmp:$libdir', true],
    'default_transaction_read_only' => ['on', 'on', false],
    'transaction_read_only' => ['on', 'on', false],
    'session_replication_role' => ['replica', 'replica', true],
    'allow_system_table_mods' => ['on', 'on', true],
    'lo_compat_privileges' => ['on', 'on', true],
    'zero_damaged_pages' => ['on', 'on', true],
    'ignore_checksum_failure' => ['on', 'on', true],
];

// --- What the guard accepts as a read is harmless on a real server ---

test('reads the guard accepts run cleanly and change nothing', function (string $sql) {
    $result = $this->live->guard($sql);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);

    // Run what the executor would run: the prepared SQL, through a cursor.
    $rows = iterator_to_array($this->live->db()->cursor($result->preparedSql()), false);

    expect($rows)->not->toBeEmpty()
        ->and($this->live->itemIds())->toBe([1, 2, 3, 4, 5])
        ->and($this->live->label(1))->toBe('one');
})->with([
    'SELECT' => 'SELECT id, label FROM items ORDER BY id',
    'CTE read' => 'WITH x AS (SELECT id FROM items WHERE id > 2) SELECT count(*) AS n FROM x',
    'EXPLAIN' => 'EXPLAIN SELECT * FROM items',
    'EXPLAIN with options, no ANALYZE' => 'EXPLAIN (COSTS off, VERBOSE) SELECT * FROM items',
    'EXPLAIN ANALYZE SELECT' => 'EXPLAIN ANALYZE SELECT * FROM items',
    'EXPLAIN (ANALYZE, BUFFERS) SELECT' => 'EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM items',
    'EXPLAIN of a DELETE, not analyzed' => 'EXPLAIN DELETE FROM items',
    'EXPLAIN of an UPDATE, ANALYZE off' => "EXPLAIN (ANALYZE off) UPDATE items SET label = 'x'",
]);

test('a harmless SET work_mem passes as a write and leaves the guarded settings alone', function () {
    $sql = "SET work_mem = '64MB'";

    // A plain SET is not a read, so it still goes through approval.
    expectLiveWrite($this->live, $sql);

    $settings = array_keys(GUARDED_PGSQL_SETTINGS);
    $before = array_map(fn (string $name): string => $this->live->setting($name), $settings);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->setting('work_mem'))->toBe('64MB')
        ->and(array_map(fn (string $name): string => $this->live->setting($name), $settings))->toBe($before);
});

// --- The PostgreSQL settings the guard refuses really change in a session ---

test('SET on a guarded PostgreSQL setting is refused and really takes effect', function (string $name, string $value, string $shown, bool $superuser) {
    if ($superuser) {
        requireLiveSuperuser($this->live);
    }

    $sql = "SET {$name} = {$value}";
    expectLiveRefused($this->live, $sql, $name);

    $db = $this->live->db();
    $db->beginTransaction();

    try {
        $before = $this->live->setting($name);
        $db->affectingStatement($sql);

        expect($before)->not->toBe($shown)
            ->and($this->live->setting($name))->toBe($shown);
    } finally {
        $db->rollBack();
    }
})->with(function (): array {
    $cases = [];

    foreach (GUARDED_PGSQL_SETTINGS as $name => $setting) {
        $cases[$name] = [$name, ...$setting];
    }

    return $cases;
});

test('set_config on a guarded setting is refused and really takes effect', function () {
    $sql = "SELECT set_config('default_transaction_read_only', 'on', true)";
    expectLiveRefused($this->live, $sql, 'default_transaction_read_only');

    $db = $this->live->db();
    $db->beginTransaction();

    try {
        $db->select($sql);

        expect($this->live->setting('default_transaction_read_only'))->toBe('on');
    } finally {
        $db->rollBack();
    }
});

test('session_replication_role = replica switches foreign keys off', function () {
    requireLiveSuperuser($this->live);

    $sql = 'SET session_replication_role = replica';
    expectLiveRefused($this->live, $sql, 'session_replication_role');

    $db = $this->live->db();
    $orphan = 'INSERT INTO children (id, parent_id) VALUES (1, 999)';

    expect(fn () => $db->statement($orphan))->toThrow(QueryException::class, 'foreign key');

    $db->beginTransaction();

    try {
        $db->affectingStatement($sql);
        $db->statement($orphan);

        expect($db->selectOne('SELECT parent_id FROM children WHERE id = 1')->parent_id)->toBe(999);
    } finally {
        $db->rollBack();
    }
});

test('transaction_read_only = off lifts a read-only transaction', function () {
    $sql = 'SET transaction_read_only = off';
    expectLiveRefused($this->live, $sql, 'transaction_read_only');

    $db = $this->live->db();
    $insert = "INSERT INTO items (id, label) VALUES (6, 'six')";

    $db->beginTransaction();

    try {
        $db->statement('SET TRANSACTION READ ONLY');

        expect(fn () => $db->statement($insert))->toThrow(QueryException::class, 'read-only transaction');
    } finally {
        $db->rollBack();
    }

    $db->beginTransaction();

    try {
        $db->statement('SET TRANSACTION READ ONLY');
        $db->affectingStatement($sql);
        $db->statement($insert);

        expect($this->live->label(6))->toBe('six');
    } finally {
        $db->rollBack();
    }
});

test('default_transaction_read_only = off lifts a read-only session default', function () {
    $sql = 'SET default_transaction_read_only = off';
    expectLiveRefused($this->live, $sql, 'default_transaction_read_only');

    // Raw PDO throughout, on purpose: Laravel treats SQLSTATE 25006 as a lost
    // connection and retries outside a transaction on a fresh, writable
    // session, so an INSERT through Laravel would succeed with or without the
    // SET under test. The PID check proves every step ran on one session.
    $pdo = $this->live->db()->getPdo();
    $pid = $this->live->backendPid();
    $insert = "INSERT INTO items (id, label) VALUES (6, 'six')";

    // A session that starts out read-only, as a read-only role would.
    $pdo->exec('SET default_transaction_read_only = on');

    expect(fn () => $pdo->exec($insert))->toThrow(PDOException::class, 'read-only transaction');

    $pdo->exec($sql);
    $pdo->exec($insert);

    expect($this->live->backendPid())->toBe($pid)
        ->and($this->live->label(6))->toBe('six');
});

// --- What the guard counts as a write really writes ---

test('EXPLAIN ANALYZE of a DELETE with a WHERE is a write and deletes the row', function (string $sql) {
    expectLiveWrite($this->live, $sql);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe([2, 3, 4, 5]);
})->with([
    'legacy syntax' => 'EXPLAIN ANALYZE DELETE FROM items WHERE id = 1',
    'option list' => 'EXPLAIN (ANALYZE, BUFFERS, COSTS off) DELETE FROM items WHERE id = 1',
    'British spelling' => 'EXPLAIN ANALYSE DELETE FROM items WHERE id = 1',
    'behind a multibyte comment' => 'EXPLAIN ANALYZE /* '.str_repeat('é', 23).' */ DELETE FROM items WHERE id = 1',
]);

test('EXPLAIN ANALYZE of a DELETE without a WHERE is refused and would empty the table', function () {
    $sql = 'EXPLAIN ANALYZE DELETE FROM items';
    expectLiveRefused($this->live, $sql, 'DELETE without a WHERE clause');

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe([]);
});

test('DML inside a CTE is a write and really writes', function (string $sql, array $ids) {
    expectLiveWrite($this->live, $sql);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe($ids);
})->with([
    'DELETE in a CTE' => ['WITH d AS (DELETE FROM items WHERE id = 2 RETURNING *) SELECT * FROM d', [1, 3, 4, 5]],
    'INSERT in a CTE' => ["WITH x AS (INSERT INTO items (id, label) VALUES (10, 'ten') RETURNING id) SELECT * FROM x", [1, 2, 3, 4, 5, 10]],
    'DELETE in a nested CTE' => ['WITH d AS (WITH x AS (SELECT 4 AS id) DELETE FROM items WHERE id IN (SELECT id FROM x) RETURNING *) SELECT * FROM d', [1, 2, 3, 5]],
]);

test('UPDATE inside a CTE is a write and really updates', function () {
    $sql = "WITH u AS (UPDATE items SET label = 'changed' WHERE id = 3 RETURNING id) SELECT * FROM u";
    expectLiveWrite($this->live, $sql);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->label(3))->toBe('changed');
});

test('DELETE in a CTE without a WHERE is refused and would empty the table', function () {
    $sql = 'WITH d AS (DELETE FROM items RETURNING *) SELECT count(*) FROM d';
    expectLiveRefused($this->live, $sql, 'DELETE without a WHERE clause');

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe([]);
});

// --- DO blocks and dynamic SQL ---

test('a DO block is a write and really writes', function (string $sql, array $ids, string $label) {
    expectLiveWrite($this->live, $sql);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe($ids)
        ->and($this->live->label(3))->toBe($label);
})->with([
    'static UPDATE' => ["DO \$\$ BEGIN UPDATE items SET label = 'done' WHERE id = 3; END \$\$", [1, 2, 3, 4, 5], 'done'],
    'dynamic DELETE through EXECUTE' => ["DO \$\$ BEGIN EXECUTE 'DELETE FROM items WHERE id = ' || 5; END \$\$", [1, 2, 3, 4], 'three'],
]);

// PRD 3.2: DO blocks are exempt from the WHERE rule. They are classified as
// Write and always go through human approval, where the reviewer sees the
// whole body. These cases pin that decision: a change here is a PRD change.
test('a DO block is exempt from the WHERE rule: a DELETE without a WHERE passes as a write and empties the table', function (string $sql) {
    expectLiveWrite($this->live, $sql);

    $this->live->db()->affectingStatement($sql);

    expect($this->live->itemIds())->toBe([]);
})->with([
    'static DELETE' => ['DO $$ BEGIN DELETE FROM items; END $$'],
    'dynamic DELETE through EXECUTE' => ["DO \$\$ BEGIN EXECUTE 'DELETE FROM items'; END \$\$"],
]);

test('PREPARE ... AS DELETE is a write, and EXECUTE runs it', function () {
    $prepare = 'PREPARE qp_live_delete (integer) AS DELETE FROM items WHERE id = $1';
    $execute = 'EXECUTE qp_live_delete(2)';

    expectLiveWrite($this->live, $prepare);
    expectLiveWrite($this->live, $execute);

    $db = $this->live->db();
    $db->affectingStatement($prepare);
    $db->affectingStatement($execute);
    $db->statement('DEALLOCATE qp_live_delete');

    expect($this->live->itemIds())->toBe([1, 3, 4, 5]);
});

// --- Blocked functions ---

test('dblink_exec is refused and writes outside the request transaction', function () {
    requireLiveSuperuser($this->live);

    $db = $this->live->db();

    // CREATE EXTENSION IF NOT EXISTS is a no-op when dblink already lives in
    // another schema, which the test session's search_path does not reach.
    // Reuse such an install through a qualified name, but not one inside
    // another live test's schema: that one is dropped when its test ends.
    $installed = $db->selectOne(
        "SELECT n.nspname AS schema FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'dblink'",
    )?->schema;

    if ($installed === null) {
        try {
            $db->statement("CREATE EXTENSION dblink WITH SCHEMA {$this->live->schema}");
        } catch (QueryException $e) {
            // 42710: a parallel worker installed it between the lookup and here.
            $this->markTestSkipped($e->getCode() === '42710'
                ? 'dblink was installed concurrently by another live test.'
                : 'The dblink extension is not available on this server: '.$e->getMessage());
        }

        $installed = $this->live->schema;
    } elseif (str_starts_with($installed, 'qp_live_')) {
        $this->markTestSkipped("dblink is installed in another live test's schema ({$installed}), which may disappear mid-test.");
    }

    $remote = sprintf("INSERT INTO %s.items (id, label) VALUES (7, 'seven')", $this->live->schema);
    $sql = sprintf(
        'SELECT %s.dblink_exec(%s, %s)',
        $db->getQueryGrammar()->wrap($installed),
        $this->live->quote($this->live->serverSideDsn()),
        $this->live->quote($remote),
    );

    expectLiveRefused($this->live, $sql, 'dblink_exec() is not allowed');

    // The request's own transaction is rolled back, yet the row stays: dblink
    // committed it on a second connection the transaction cannot reach.
    $db->beginTransaction();

    try {
        $db->select($sql);
    } finally {
        $db->rollBack();
    }

    expect($this->live->label(7))->toBe('seven');
});

test('pg_terminate_backend is refused and really ends another session', function () {
    $victim = $this->live->otherSession();
    $pid = (int) $victim->selectOne('SELECT pg_backend_pid() AS pid')->pid;

    $sql = "SELECT pg_terminate_backend({$pid}, 5000)";
    expectLiveRefused($this->live, $sql, 'pg_terminate_backend() is not allowed');

    expect($this->live->db()->selectOne($sql.' AS terminated')->terminated)->toBeTrue()
        ->and($this->live->db()->selectOne('SELECT count(*) AS n FROM pg_stat_activity WHERE pid = ?', [$pid])->n)->toBe(0);
});

test('pg_cancel_backend is refused and really cancels a running query', function () {
    // The victim is the test session's own query: pg_cancel_backend signals
    // the backend, and the pg_sleep running beside it in the same statement
    // is interrupted instead of finishing.
    $sql = 'SELECT pg_cancel_backend(pg_backend_pid()) AS cancelled, pg_sleep(30)';
    expectLiveRefused($this->live, $sql, 'pg_cancel_backend() is not allowed');

    $started = microtime(true);

    expect(fn () => $this->live->db()->select($sql))->toThrow(QueryException::class, 'canceling statement due to user request')
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

// --- PostgreSQL reads backslashes and "#" differently from the lexer ---

test('a backslash in a plain string is refused and PostgreSQL does not read it as an escape', function () {
    $sql = "SELECT 'a\\' AS x";
    expectLiveRefused($this->live, $sql, 'reads a backslash');

    // standard_conforming_strings is on: the string ends at the second quote
    // and keeps its backslash, where the lexer would read an escaped quote.
    expect($this->live->db()->selectOne($sql)->x)->toBe('a\\');
});

test('"#" is refused and PostgreSQL reads it as the XOR operator, not a comment', function () {
    $sql = 'SELECT 1 # 3 AS x';
    expectLiveRefused($this->live, $sql, '"#" is an operator');

    expect((int) $this->live->db()->selectOne($sql)->x)->toBe(2);
});
