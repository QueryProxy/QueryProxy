<?php

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\QueryExecutor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

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
    DB::extend('fake_session', function (array $config, string $name) {
        return new class(new PDO('sqlite:'.$config['database']), $config['database'], '', $config + ['name' => $name]) extends SQLiteConnection
        {
            public function unprepared($query)
            {
                if (str_starts_with($query, 'SET ')) {
                    $this->event(new QueryExecuted($query, [], 0, $this));

                    return true;
                }

                return parent::unprepared($query);
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
        'SELECT id FROM customers',
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
