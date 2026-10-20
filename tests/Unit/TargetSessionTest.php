<?php

use App\Enums\DbDriver;
use App\Services\Execution\TargetSession;
use Illuminate\Database\SQLiteConnection;

beforeEach(function () {
    config(['queryproxy.execution_timeout' => 65, 'queryproxy.lock_timeout' => 7]);
});

test('the statement timeout is the execution timeout minus five seconds, never below one', function (int $execution, int $expected) {
    config(['queryproxy.execution_timeout' => $execution]);

    expect(app(TargetSession::class)->statementTimeoutSeconds())->toBe($expected);
})->with([
    'default 300' => [300, 295],
    'custom' => [65, 60],
    'at the margin' => [5, 1],
    'below the margin' => [3, 1],
    'invalid falls back to the default' => [0, 295],
]);

test('the lock timeout comes from QUERYPROXY_LOCK_TIMEOUT, invalid values fall back to the default', function () {
    expect(app(TargetSession::class)->lockTimeoutSeconds())->toBe(7);

    config(['queryproxy.lock_timeout' => 0]);

    expect(app(TargetSession::class)->lockTimeoutSeconds())->toBe(5);
});

test('limits that were split across a reconnect are set again on the new session', function () {
    $session = app(TargetSession::class);

    $db = new class(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'reconnect_fake']) extends SQLiteConnection
    {
        public array $sent = [];

        public int $calls = 0;

        public function unprepared($query)
        {
            $this->sent[] = $query;

            // The second statement hits a lost connection: Laravel reconnects
            // and re-runs only that statement on the new session.
            if (++$this->calls === 2) {
                $this->setPdo(new PDO('sqlite::memory:'));
                $this->sent[] = $query;
            }

            return true;
        }
    };

    $session->applyLimits($db, DbDriver::Mysql, false);

    expect($db->sent)->toBe([
        'SET SESSION MAX_EXECUTION_TIME = 60000',
        'SET SESSION innodb_lock_wait_timeout = 7',
        'SET SESSION innodb_lock_wait_timeout = 7',
        'SET SESSION MAX_EXECUTION_TIME = 60000',
        'SET SESSION innodb_lock_wait_timeout = 7',
    ]);
});

test('setting limits gives up when the connection keeps dropping', function () {
    $db = new class(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'flaky_fake']) extends SQLiteConnection
    {
        public function unprepared($query)
        {
            $this->setPdo(new PDO('sqlite::memory:'));

            return true;
        }
    };

    expect(fn () => app(TargetSession::class)->applyLimits($db, DbDriver::Mysql, false))
        ->toThrow(RuntimeException::class, 'kept dropping');
});

test('timeout statements per driver', function (DbDriver $driver, bool $transactional, array $expected) {
    expect(app(TargetSession::class)->limitStatements($driver, $transactional))->toBe($expected);
})->with([
    'pgsql in a transaction' => [DbDriver::Pgsql, true, [
        'SET LOCAL statement_timeout = 60000',
        'SET LOCAL lock_timeout = 7000',
        'SET LOCAL idle_in_transaction_session_timeout = 65000',
    ]],
    'pgsql outside a transaction' => [DbDriver::Pgsql, false, [
        'SET statement_timeout = 60000',
        'SET lock_timeout = 7000',
    ]],
    'mysql' => [DbDriver::Mysql, false, [
        'SET SESSION MAX_EXECUTION_TIME = 60000',
        'SET SESSION innodb_lock_wait_timeout = 7',
    ]],
    'mariadb' => [DbDriver::Mariadb, true, [
        'SET SESSION max_statement_time = 60',
        'SET SESSION innodb_lock_wait_timeout = 7',
    ]],
    'sqlsrv' => [DbDriver::Sqlsrv, false, ['SET LOCK_TIMEOUT 7000']],
    'sqlite has no session settings' => [DbDriver::Sqlite, false, []],
]);

test('PDO options carry the timeouts of the drivers that take them there', function () {
    $session = app(TargetSession::class);

    expect($session->pdoOptions(DbDriver::Sqlsrv))->toBe([1001 => 60])
        ->and($session->pdoOptions(DbDriver::Sqlite))->toBe([PDO::ATTR_TIMEOUT => 7])
        ->and($session->pdoOptions(DbDriver::Pgsql))->toBe([])
        ->and($session->pdoOptions(DbDriver::Mysql))->toBe([])
        ->and($session->pdoOptions(DbDriver::Mariadb))->toBe([]);
});

test('read-only violations are recognised across drivers', function (string $message, bool $expected) {
    expect(TargetSession::isReadOnlyViolation(new RuntimeException($message)))->toBe($expected);
})->with([
    'pgsql' => ['SQLSTATE[25006]: Read only sql transaction: 7 ERROR:  cannot execute INSERT in a read-only transaction', true],
    'mysql' => ['SQLSTATE[25006]: Read only sql transaction: 1792 Cannot execute statement in a READ ONLY transaction.', true],
    'mysql --read-only' => ['The MySQL server is running with the --read-only option so it cannot execute this statement', true],
    'sqlite' => ['SQLSTATE[HY000]: General error: 8 attempt to write a readonly database', true],
    'unrelated' => ['SQLSTATE[42P01]: Undefined table: relation "x" does not exist', false],
]);

test('a read-only violation wrapped in another exception is still recognised', function () {
    $wrapped = new RuntimeException('query failed', 0, new RuntimeException('cannot execute DELETE in a read-only transaction'));

    expect(TargetSession::isReadOnlyViolation($wrapped))->toBeTrue();
});
