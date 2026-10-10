<?php

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Notifications\QueryRequestFinished;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\QueryExecutor;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Creates a SQLite target database with sample data and a queued request against it.
 */
function executionSetup(string $preparedSql, array $overrides = []): array
{
    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_target_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO customers (name, email) VALUES ('Ada Lovelace', 'ada@example.com'), ('Grace Hopper', 'grace@navy.mil'), ('Alan Turing', 'alan@bletchley.uk')");
    unset($pdo);

    $team = Team::factory()->create();
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id]);

    $request = QueryRequest::factory()->create(array_merge([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'sql_original' => $preparedSql,
        'sql_prepared' => $preparedSql,
        'status' => QueryRequestStatus::Queued,
    ], $overrides));

    return [$request, $dbFile, $team];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_target_*') ?: [] as $file) {
        @unlink($file);
    }
});

test('a read request streams results to ndjson storage', function () {
    [$request] = executionSetup('SELECT id, name, email FROM customers ORDER BY id LIMIT 1000');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->result_row_count)->toBe(3)
        ->and($request->result_columns)->toBe(['id', 'name', 'email'])
        ->and($request->duration_ms)->not->toBeNull();

    $lines = array_filter(explode("\n", Storage::disk('local')->get($request->result_path)));

    expect($lines)->toHaveCount(3)
        // New teams start with the default masking rules, so `email` is masked.
        ->and(json_decode($lines[0], true))->toBe([1, 'Ada Lovelace', 'a***@***.com']);
});

test('masking rules are applied before results hit storage', function () {
    [$request, , $team] = executionSetup('SELECT name, email FROM customers ORDER BY id LIMIT 10');

    // Start from an empty rule set so only the rule below can do the masking.
    $team->maskingRules()->delete();
    MaskingRule::factory()->create(['team_id' => $team->id]);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $content = Storage::disk('local')->get($request->result_path);

    expect($content)->not->toContain('ada@example.com')
        ->and($content)->toContain('a***@***.com')
        ->and($content)->toContain('Ada Lovelace');
});

test('a write request records affected rows', function () {
    [$request, $dbFile] = executionSetup('UPDATE customers SET active = 0 WHERE id > 1', ['type' => 'write']);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->affected_rows)->toBe(2)
        ->and($request->result_path)->toBeNull();

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT count(*) FROM customers WHERE active = 0')->fetchColumn())->toBe(2);
});

test('a failing transaction rolls back completely', function () {
    $sql = "UPDATE customers SET active = 0 WHERE id = 1;\nUPDATE nonexistent_table SET x = 1 WHERE id = 1";
    [$request, $dbFile] = executionSetup($sql, ['type' => 'write', 'is_transaction' => true, 'statement_count' => 2]);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('nonexistent_table');

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT count(*) FROM customers WHERE active = 0')->fetchColumn())->toBe(0);
});

test('invalid sql marks the request failed with the error message', function () {
    [$request] = executionSetup('SELECT * FROM missing_table LIMIT 10');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('missing_table');
});

test('a failed query stores, audits and notifies a masked error message', function () {
    [$request] = executionSetup(
        "INSERT INTO customers (id, name, email) VALUES (1, 'Dup', 'dup@example.com')",
        ['type' => 'write'],
    );

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $audit = AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_failed')->sole();
    $notification = $request->requester->notifications()->sole();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('UNIQUE constraint failed')
        ->and($request->error_message)->not->toContain('dup@example.com')
        ->and($audit->metadata['error'])->toBe($request->error_message)
        ->and($notification->data['message'])->not->toContain('dup@example.com');
});

test('a failed query with no sensitive content keeps its readable error message', function () {
    [$request] = executionSetup('SELECT * FROM missing_table LIMIT 10');

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->error_message)->toContain('no such table: missing_table');
});

test('execution notifies the requester on completion', function () {
    Notification::fake();

    [$request] = executionSetup('SELECT id FROM customers LIMIT 5');

    app(QueryExecutor::class)->execute($request);

    Notification::assertSentTo(
        $request->requester,
        QueryRequestFinished::class,
    );
});

test('requests in a final state are not re-executed', function () {
    [$request] = executionSetup('SELECT id FROM customers LIMIT 5', ['status' => QueryRequestStatus::Completed]);

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->result_path)->toBeNull();
});

test('a publicly visible result disk is refused instead of publishing rows', function () {
    [$request] = executionSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');

    Storage::fake('public');
    config()->set('queryproxy.result_disk', 'public');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->result_path)->toBeNull()
        ->and($request->error_message)->toContain('publicly visible')
        ->and($request->error_message)->toContain('QUERYPROXY_RESULT_DISK')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a custom disk declared public is refused as well', function () {
    [$request] = executionSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');

    Storage::fake('exports');
    config()->set('filesystems.disks.exports.visibility', 'public');
    config()->set('queryproxy.result_disk', 'exports');

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
});

/**
 * Turns the request's target into a MySQL / MariaDB connection whose session
 * reports $sqlMode: the configured connection answers the sql_mode read with
 * that value and runs everything else against the SQLite target file, so a
 * statement that reaches the database leaves a trace there.
 */
function fakeMysqlSession(QueryRequest $request, string $dbFile, DbDriver $driver, string|Throwable $sqlMode): void
{
    $request->connection->forceFill(['driver' => $driver])->save();

    DB::extend('fake_mysql_session', function (array $config) use ($sqlMode) {
        return new class(new PDO('sqlite:'.$config['database']), $config['database'], '', $config, $sqlMode) extends SQLiteConnection
        {
            public function __construct($pdo, $database, $tablePrefix, $config, private string|Throwable $sqlMode)
            {
                parent::__construct($pdo, $database, $tablePrefix, $config);
            }

            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                if ($query !== 'SELECT @@SESSION.sql_mode') {
                    return parent::scalar($query, $bindings, $useReadPdo);
                }

                if ($this->sqlMode instanceof Throwable) {
                    throw $this->sqlMode;
                }

                return $this->sqlMode;
            }
        };
    });

    test()->partialMock(DynamicConnectionFactory::class, function ($mock) use ($dbFile) {
        $mock->shouldReceive('configure')->andReturnUsing(function ($connection) use ($dbFile) {
            $name = 'queryproxy_target_'.$connection->id;

            config(["database.connections.{$name}" => [
                'driver' => 'fake_mysql_session',
                'database' => $dbFile,
                'prefix' => '',
            ]]);

            return $name;
        });
    });
}

test('a MySQL / MariaDB session whose sql_mode changes quoting is refused before the SQL runs', function (DbDriver $driver, string $sqlMode, string $flag) {
    [$request, $dbFile] = executionSetup('UPDATE customers SET active = 0 WHERE id > 1', ['type' => 'write']);
    fakeMysqlSession($request, $dbFile, $driver, $sqlMode);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain($flag)
        ->and($request->error_message)->toContain('sql_mode')
        ->and($request->error_message)->toContain($driver->label())
        ->and($request->affected_rows)->toBeNull();

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT count(*) FROM customers WHERE active = 0')->fetchColumn())->toBe(0);
})->with([
    'mysql, ANSI_QUOTES' => [DbDriver::Mysql, 'ONLY_FULL_GROUP_BY,ANSI_QUOTES,STRICT_TRANS_TABLES', 'ANSI_QUOTES'],
    'mariadb, ANSI_QUOTES alone' => [DbDriver::Mariadb, 'ANSI_QUOTES', 'ANSI_QUOTES'],
    'mysql, NO_BACKSLASH_ESCAPES in lower case' => [DbDriver::Mysql, 'strict_trans_tables,no_backslash_escapes', 'NO_BACKSLASH_ESCAPES'],
    'mariadb, both flags' => [DbDriver::Mariadb, 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES and ANSI_QUOTES'],
    'mysql, ANSI combination mode expanded by the server' => [DbDriver::Mysql, 'REAL_AS_FLOAT,PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ONLY_FULL_GROUP_BY,ANSI', 'ANSI_QUOTES'],
]);

test('a session whose sql_mode cannot be read is refused before the SQL runs', function () {
    [$request, $dbFile] = executionSetup('UPDATE customers SET active = 0 WHERE id > 1', ['type' => 'write']);
    fakeMysqlSession($request, $dbFile, DbDriver::Mariadb, new RuntimeException('sql_mode read failed'));

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->affected_rows)->toBeNull();

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT count(*) FROM customers WHERE active = 0')->fetchColumn())->toBe(0);
});

test('a read on a refused session writes no result file', function () {
    [$request, $dbFile] = executionSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');
    fakeMysqlSession($request, $dbFile, DbDriver::Mysql, 'ANSI_QUOTES');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->result_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a MySQL / MariaDB session in the default quoting mode runs the request', function (DbDriver $driver, string $sqlMode) {
    [$request, $dbFile] = executionSetup('UPDATE customers SET active = 0 WHERE id > 1', ['type' => 'write']);
    fakeMysqlSession($request, $dbFile, $driver, $sqlMode);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->affected_rows)->toBe(2);
})->with([
    'mysql, Laravel strict mode' => [DbDriver::Mysql, 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'],
    'mariadb, empty mode' => [DbDriver::Mariadb, ''],
    'mysql, a flag that only looks similar' => [DbDriver::Mysql, 'ANSI_QUOTES_X,NO_BACKSLASH_ESCAPES_LEGACY'],
]);

test('drivers without a quoting sql_mode are not asked for one', function () {
    [$request] = executionSetup('SELECT id FROM customers LIMIT 5');

    DB::listen(function ($query) {
        expect($query->sql)->not->toContain('sql_mode');
    });

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed);
});
