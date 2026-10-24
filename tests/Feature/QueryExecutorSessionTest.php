<?php

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\BackendCanceller;
use App\Services\Execution\QueryExecutor;
use App\Services\Execution\ResultStreamer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/** A SQLite PDO that records (and can refuse) the MySQL buffered-query switch. */
class SessionRecordingPdo extends PDO
{
    /** @var list<array{buffered: bool, inTransaction: bool}> */
    public static array $bufferedQueryCalls = [];

    public static bool $refuseBufferedQuery = false;

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === 1000) {
            self::$bufferedQueryCalls[] = ['buffered' => (bool) $value, 'inTransaction' => $this->inTransaction()];

            return ! self::$refuseBufferedQuery;
        }

        return parent::setAttribute($attribute, $value);
    }
}

/**
 * Database-side session hardening: READ ONLY transaction for reads and the
 * statement / lock timeouts, per driver. The target is a SQLite file; for the
 * MySQL / MariaDB / PostgreSQL / SQL Server paths a SQLite-backed fake session
 * swallows the SET statements the executor issues and reports them to
 * DB::listen, so the tests assert on the SQL that would be sent.
 */
function sessionSetup(string $sql, string $type = 'read', ?DbDriver $driver = null, array $connectionAttributes = []): array
{
    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_target_session_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO customers (name) VALUES ('Ada'), ('Grace'), ('Alan')");
    unset($pdo);

    $team = Team::factory()->create();
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id] + $connectionAttributes);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
        'status' => QueryRequestStatus::Queued,
        'type' => $type,
    ]);

    if ($driver !== null) {
        $connection->forceFill(['driver' => $driver])->save();
        $request->setRelation('connection', $connection->fresh());
        sessionFakeDriver($dbFile);
    }

    return [$request, $dbFile, $connection];
}

/** Route the target (and its read identity) to a SQLite-backed session that ignores SET statements. */
function sessionFakeDriver(string $dbFile): void
{
    SessionRecordingPdo::$bufferedQueryCalls = [];
    SessionRecordingPdo::$refuseBufferedQuery = false;

    DB::extend('fake_session', function (array $config, string $name) {
        return new class(new SessionRecordingPdo('sqlite:'.$config['database']), $config['database'], '', $config + ['name' => $name]) extends SQLiteConnection
        {
            private ?string $cursorSql = null;

            private int $cursorOffset = 0;

            public function unprepared($query)
            {
                if (str_starts_with($query, 'SET ')
                    || preg_match('/^(SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT|CLOSE) /', $query) === 1) {
                    $this->event(new QueryExecuted($query, [], 0, $this));

                    return true;
                }

                return parent::unprepared($query);
            }

            // PostgreSQL's server-side cursor, emulated: DECLARE remembers the
            // query, FETCH FORWARD n replays the next n rows of it.
            public function statement($query, $bindings = [])
            {
                $prefix = 'DECLARE qp_cursor NO SCROLL CURSOR FOR ';

                if (! str_starts_with($query, $prefix)) {
                    return parent::statement($query, $bindings);
                }

                $this->event(new QueryExecuted($query, [], 0, $this));

                if (str_contains($query, '/*no-cursor*/')) {
                    throw new RuntimeException('cannot declare a cursor for this statement');
                }

                $this->cursorSql = substr($query, strlen($prefix));
                $this->cursorOffset = 0;

                return true;
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                if (preg_match('/^FETCH FORWARD (\d+) FROM qp_cursor$/', $query, $match) !== 1) {
                    return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
                }

                $statement = $this->getPdo()->query(sprintf('SELECT * FROM (%s) LIMIT %d OFFSET %d', $this->cursorSql, $match[1], $this->cursorOffset));
                $rows = $statement->fetchAll(PDO::FETCH_OBJ);
                $this->cursorOffset += count($rows);
                $this->event(new QueryExecuted($query, [], 0, $this));

                return $rows;
            }

            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                return match ($query) {
                    'SELECT @@SESSION.sql_mode' => 'STRICT_TRANS_TABLES',
                    'SELECT DATABASE()' => 'app',
                    default => parent::scalar($query, $bindings, $useReadPdo),
                };
            }
        };
    });

    test()->partialMock(DynamicConnectionFactory::class, function ($mock) use ($dbFile) {
        foreach (['configure' => '', 'configureRead' => '_read'] as $method => $suffix) {
            $mock->shouldReceive($method)->andReturnUsing(function ($connection) use ($dbFile, $suffix) {
                $name = 'queryproxy_target_'.$connection->id.$suffix;

                config(["database.connections.{$name}" => [
                    'driver' => 'fake_session',
                    'database' => $dbFile,
                    'prefix' => '',
                ]]);

                return $name;
            });
        }
    });
}

/**
 * Record, in order, everything sent to a target connection: SQL text plus
 * BEGIN / COMMIT / ROLLBACK markers for transactions.
 *
 * @return ArrayObject<int, array{sql: string, connection: string}>
 */
function sessionCollectQueries(): ArrayObject
{
    $log = new ArrayObject;

    DB::listen(function ($query) use ($log) {
        if (str_starts_with($query->connectionName, 'queryproxy_target_')) {
            $log[] = ['sql' => $query->sql, 'connection' => $query->connectionName];
        }
    });

    foreach ([TransactionBeginning::class => 'BEGIN', TransactionCommitted::class => 'COMMIT', TransactionRolledBack::class => 'ROLLBACK'] as $event => $marker) {
        Event::listen($event, function ($event) use ($log, $marker) {
            if (str_starts_with($event->connectionName, 'queryproxy_target_')) {
                $log[] = ['sql' => $marker, 'connection' => $event->connectionName];
            }
        });
    }

    return $log;
}

/** @return list<string> */
function sessionSent(ArrayObject $log): array
{
    return array_column($log->getArrayCopy(), 'sql');
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_target_session_*') ?: [] as $file) {
        @unlink($file);
    }
});

test('a read on SQLite runs with query_only on, so the database refuses a write', function () {
    [$request, $dbFile] = sessionSetup('DELETE FROM customers', 'read');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('read-only database session')
        ->and($request->error_message)->toContain('write request');

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT count(*) FROM customers')->fetchColumn())->toBe(3);
});

test('a plain read on SQLite still completes under query_only', function () {
    [$request] = sessionSetup('SELECT id, name FROM customers ORDER BY id');

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->fresh()->result_row_count)->toBe(3);
});

test('a read opens a READ ONLY transaction and sets the timeouts for the driver', function (DbDriver $driver, array $expected) {
    config(['queryproxy.execution_timeout' => 65, 'queryproxy.lock_timeout' => 7]);
    [$request] = sessionSetup('SELECT id FROM customers', 'read', $driver);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed);

    $sent = sessionSent($log);

    // Only what comes before the user's SELECT matters; the sql_mode probe of
    // the MySQL family is dropped.
    $sent = array_values(array_filter($sent, fn ($sql) => $sql !== 'SELECT @@SESSION.sql_mode'));

    expect($sent)->toBe($expected);
})->with([
    'pgsql' => [DbDriver::Pgsql, [
        'BEGIN',
        'SET TRANSACTION READ ONLY',
        'SET LOCAL statement_timeout = 60000',
        'SET LOCAL lock_timeout = 7000',
        'SET LOCAL idle_in_transaction_session_timeout = 65000',
        'SAVEPOINT qp_declare',
        'DECLARE qp_cursor NO SCROLL CURSOR FOR SELECT id FROM customers',
        'RELEASE SAVEPOINT qp_declare',
        'FETCH FORWARD 500 FROM qp_cursor',
        'CLOSE qp_cursor',
        'COMMIT',
    ]],
    'mysql' => [DbDriver::Mysql, [
        'SET SESSION MAX_EXECUTION_TIME = 60000',
        'SET SESSION innodb_lock_wait_timeout = 7',
        'SET TRANSACTION READ ONLY',
        'BEGIN',
        'SELECT id FROM customers',
        'COMMIT',
    ]],
    'mariadb' => [DbDriver::Mariadb, [
        'SET SESSION max_statement_time = 60',
        'SET SESSION innodb_lock_wait_timeout = 7',
        'SET TRANSACTION READ ONLY',
        'BEGIN',
        'SELECT id FROM customers',
        'COMMIT',
    ]],
    // No engine-level read-only mode: timeouts only, the read user is the protection.
    'sqlsrv' => [DbDriver::Sqlsrv, [
        'SET LOCK_TIMEOUT 7000',
        'SELECT id FROM customers',
    ]],
]);

test('a failed read rolls its READ ONLY transaction back and the request fails', function () {
    [$request] = sessionSetup('SELECT id FROM missing_table', 'read', DbDriver::Pgsql);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and(sessionSent($log))->toContain('ROLLBACK')
        ->and(sessionSent($log))->not->toContain('COMMIT');
});

test('writes get the timeouts too: pgsql scopes them to the transaction, a lone pgsql write sets them on the session', function () {
    config(['queryproxy.execution_timeout' => 65, 'queryproxy.lock_timeout' => 7]);

    [$single] = sessionSetup('UPDATE customers SET active = 0 WHERE id = 1', 'write', DbDriver::Pgsql);
    $log = sessionCollectQueries();
    app(QueryExecutor::class)->execute($single);

    expect($single->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(sessionSent($log))->toBe([
            'SET statement_timeout = 60000',
            'SET lock_timeout = 7000',
            'UPDATE customers SET active = 0 WHERE id = 1',
        ]);

    [$atomic] = sessionSetup('UPDATE customers SET active = 0 WHERE id = 1', 'write', DbDriver::Pgsql);
    $atomic->forceFill(['is_transaction' => true])->save();
    $log = sessionCollectQueries();
    app(QueryExecutor::class)->execute($atomic->fresh());

    expect($atomic->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(array_slice(sessionSent($log), -6))->toBe([
            'BEGIN',
            'SET LOCAL statement_timeout = 60000',
            'SET LOCAL lock_timeout = 7000',
            'SET LOCAL idle_in_transaction_session_timeout = 65000',
            'UPDATE customers SET active = 0 WHERE id = 1',
            'COMMIT',
        ]);
});

test('a write on MariaDB gets max_statement_time and the lock wait timeout', function () {
    config(['queryproxy.execution_timeout' => 65, 'queryproxy.lock_timeout' => 7]);
    [$request] = sessionSetup('UPDATE customers SET active = 0 WHERE id = 1', 'write', DbDriver::Mariadb);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    $sent = sessionSent($log);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($sent)->toContain('SET SESSION max_statement_time = 60', 'SET SESSION innodb_lock_wait_timeout = 7')
        ->and($sent)->not->toContain('SET TRANSACTION READ ONLY');
});

test('a read logs in with the read identity when the connection has one, a write never does', function () {
    [$read] = sessionSetup('SELECT id FROM customers', 'read', DbDriver::Mysql, ['read_username' => 'reader', 'read_password' => 'r-secret']);
    $log = sessionCollectQueries();
    app(QueryExecutor::class)->execute($read);

    $selectConnection = collect($log)->firstWhere('sql', 'SELECT id FROM customers')['connection'];

    expect($read->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($selectConnection)->toBe('queryproxy_target_'.$read->connection_id.'_read')
        ->and(collect($log)->where('connection', 'queryproxy_target_'.$read->connection_id)->where('sql', 'SET TRANSACTION READ ONLY'))->toBeEmpty();

    [$write] = sessionSetup('UPDATE customers SET active = 0 WHERE id = 1', 'write', DbDriver::Mysql, ['read_username' => 'reader', 'read_password' => 'r-secret']);
    $log = sessionCollectQueries();
    app(QueryExecutor::class)->execute($write);

    expect($write->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(collect($log)->pluck('connection')->unique()->all())->toBe(['queryproxy_target_'.$write->connection_id]);
});

test('a read without a read identity stays on the main connection', function () {
    [$request] = sessionSetup('SELECT id FROM customers', 'read', DbDriver::Mysql);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    expect(collect($log)->pluck('connection')->unique()->all())->toBe(['queryproxy_target_'.$request->connection_id]);
});

// ---------------------------------------------------------------------------
// Unbuffered fetch (ADR-003 amendment)
// ---------------------------------------------------------------------------

test('a mysql or mariadb read switches to unbuffered fetch inside the transaction and back before the commit', function (DbDriver $driver) {
    [$request] = sessionSetup('SELECT id, name FROM customers ORDER BY id', 'read', $driver);

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->fresh()->result_row_count)->toBe(3)
        ->and(SessionRecordingPdo::$bufferedQueryCalls)->toBe([
            ['buffered' => false, 'inTransaction' => true],
            ['buffered' => true, 'inTransaction' => true],
        ]);
})->with([DbDriver::Mysql, DbDriver::Mariadb]);

test('a mysql read fails closed when the connection cannot be made unbuffered', function () {
    [$request] = sessionSetup('SELECT id FROM customers', 'read', DbDriver::Mysql);
    SessionRecordingPdo::$refuseBufferedQuery = true;
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and(sessionSent($log))->not->toContain('SELECT id FROM customers')
        ->and(sessionSent($log))->toContain('ROLLBACK');
});

test('a mysql or mariadb read cut off at the ceiling asks the server to abort the statement before freeing it', function (DbDriver $driver) {
    config(['queryproxy.select_hard_limit' => 2]);
    [$request, , $connection] = sessionSetup('SELECT id FROM customers ORDER BY id', 'read', $driver);
    $log = sessionCollectQueries();

    $this->mock(BackendCanceller::class, function ($mock) use ($connection) {
        $mock->shouldReceive('identify')->once()->andReturn(['pid' => 77, 'token' => null]);
        $mock->shouldReceive('cancel')
            ->once()
            ->withArgs(fn ($target, int $pid, ?string $token, bool $asReader) => $target->is($connection) && $pid === 77 && $token === null && $asReader === false)
            ->andReturn(true);
    });

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    // The transaction is read-only, so it is ended with a rollback.
    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->result_row_count)->toBe(2)
        ->and($request->result_truncated)->toBeTrue()
        ->and(array_slice(sessionSent($log), -1))->toBe(['ROLLBACK'])
        ->and(sessionSent($log))->not->toContain('COMMIT')
        ->and(SessionRecordingPdo::$bufferedQueryCalls)->toBe([
            ['buffered' => false, 'inTransaction' => true],
            ['buffered' => true, 'inTransaction' => true],
        ]);
})->with([DbDriver::Mysql, DbDriver::Mariadb]);

test('a read that is not cut off, or runs on another driver, never aborts its own statement', function (DbDriver $driver, int $ceiling) {
    config(['queryproxy.select_hard_limit' => $ceiling]);
    [$request] = sessionSetup('SELECT id FROM customers ORDER BY id', 'read', $driver);
    $log = sessionCollectQueries();

    $this->mock(BackendCanceller::class, function ($mock) {
        $mock->shouldReceive('identify')->once()->andReturn(['pid' => 77, 'token' => null]);
        $mock->shouldNotReceive('cancel');
    });

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(sessionSent($log))->not->toContain('ROLLBACK');
})->with([
    'mysql under the ceiling' => [DbDriver::Mysql, 100],
    'pgsql over the ceiling' => [DbDriver::Pgsql, 2],
    'sqlsrv over the ceiling' => [DbDriver::Sqlsrv, 2],
]);

test('reads on the other drivers never touch the buffered-query attribute', function (?DbDriver $driver) {
    [$request] = sessionSetup('SELECT id FROM customers', 'read', $driver);

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(SessionRecordingPdo::$bufferedQueryCalls)->toBe([]);
})->with([DbDriver::Pgsql, DbDriver::Sqlsrv]);

test('a pgsql read pulls the rows through a server-side cursor in batches and stops one row past the ceiling', function () {
    config(['queryproxy.select_hard_limit' => 1200]);
    $sql = 'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 1500) SELECT i FROM n';
    [$request] = sessionSetup($sql, 'read', DbDriver::Pgsql);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $sent = sessionSent($log);

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->result_row_count)->toBe(1200)
        ->and($request->result_truncated)->toBeTrue()
        ->and(array_values(array_filter($sent, fn ($q) => str_starts_with($q, 'FETCH'))))->toBe([
            'FETCH FORWARD 500 FROM qp_cursor',
            'FETCH FORWARD 500 FROM qp_cursor',
            'FETCH FORWARD 201 FROM qp_cursor',
        ])
        ->and(array_slice($sent, -2))->toBe(['CLOSE qp_cursor', 'COMMIT']);
});

test('a pgsql read that ends before the ceiling is not marked truncated', function () {
    [$request] = sessionSetup('SELECT id FROM customers', 'read', DbDriver::Pgsql);

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->result_row_count)->toBe(3)
        ->and($request->fresh()->result_truncated)->toBeFalse();
});

test('a pgsql statement the server will not declare as a cursor still runs as a plain query, and says so', function () {
    Log::spy();

    [$request] = sessionSetup('SELECT id FROM customers /*no-cursor*/', 'read', DbDriver::Pgsql);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    $sent = sessionSent($log);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->fresh()->result_row_count)->toBe(3)
        ->and($sent)->toContain('ROLLBACK TO SAVEPOINT qp_declare')
        ->and($sent)->toContain('SELECT id FROM customers /*no-cursor*/')
        ->and($sent)->not->toContain('FETCH FORWARD 500 FROM qp_cursor');

    // The fallback holds the whole result in client memory, so it must not be silent,
    // and the log line must not carry the SQL or the server's message.
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'Server-side cursor refused')
        && $context['query_request_id'] === $request->id
        && $context['exception'] === RuntimeException::class
        && ! str_contains(json_encode($context), 'customers')
        && ! str_contains(json_encode($context), 'cannot declare'));
});

test('a pgsql statement that is declared as a cursor logs no warning', function () {
    Log::spy();

    [$request] = sessionSetup('SELECT id FROM customers', 'read', DbDriver::Pgsql);

    app(QueryExecutor::class)->execute($request);

    Log::shouldNotHaveReceived('warning');
});

test('a pgsql statement behind leading comments is still read through a cursor', function (string $sql) {
    [$request] = sessionSetup($sql, 'read', DbDriver::Pgsql);
    $log = sessionCollectQueries();

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(sessionSent($log))->toContain('FETCH FORWARD 500 FROM qp_cursor');
})->with([
    'block comment' => ['/* daily report */ SELECT id FROM customers'],
    'line comment' => ["-- daily report\nSELECT id FROM customers"],
    'comment after parenthesis' => ['( /* x */ SELECT id FROM customers)'],
    // Nested comments are covered by the isDeclarable() dataset: the fake server here is SQLite, which has none.
]);

test('only statements PostgreSQL can declare as a cursor are wrapped', function (string $sql, bool $declarable) {
    expect(ResultStreamer::isDeclarable($sql))->toBe($declarable);
})->with([
    'select' => ['SELECT 1', true],
    'lower case with leading whitespace' => ["  \n select 1", true],
    'parenthesised select' => ['(SELECT 1) UNION (SELECT 2)', true],
    'with' => ['WITH a AS (SELECT 1) SELECT * FROM a', true],
    'values' => ['VALUES (1), (2)', true],
    'table' => ['TABLE customers', true],
    'show' => ['SHOW server_version', false],
    'explain' => ['EXPLAIN SELECT 1', false],
    'block comment before select' => ['/* hi */ SELECT 1', true],
    'line comment before select' => ["-- daily report\nSELECT 1", true],
    'several comments and blank lines' => ["-- a\n\n/* b */\n  -- c\nSELECT 1", true],
    'nested block comment' => ['/* a /* b */ c */ SELECT 1', true],
    'comment before parenthesised select' => ['/* x */ (SELECT 1) UNION (SELECT 2)', true],
    'comment after opening parenthesis' => ['( /* x */ SELECT 1) UNION (SELECT 2)', true],
    'line comment after opening parenthesis' => ["(-- x\nSELECT 1)", true],
    'nested parentheses with comments' => ["/* a */ ( /* b */ ( -- c\n WITH t AS (SELECT 1) SELECT * FROM t))", true],
    'comment after parenthesis before show' => ['( /* x */ SHOW server_version)', false],
    'parenthesis then unterminated comment' => ['( /* never closed SELECT 1', false],
    'bare parenthesis' => ['(', false],
    'comment before show' => ['/* x */ SHOW server_version', false],
    'comment only' => ['-- nothing here', false],
    'unterminated block comment' => ['/* never closed SELECT 1', false],
    'unterminated nested block comment' => ['/* a /* b */ SELECT 1', false],
    'keyword hidden in a comment' => ['/* SELECT 1 */ SHOW server_version', false],
    'identifier that starts like a keyword' => ['selected_rows()', false],
]);
