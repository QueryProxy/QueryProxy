<?php

/*
 * Stopping a running statement on a real server (Faz 03): the app reads the
 * session id (and, on PostgreSQL and SQL Server, a start stamp) of the session
 * a run executes on and signals that session from a second connection:
 * pg_cancel_backend, KILL QUERY or KILL.
 *
 * The first tests work on idle sessions and prove what the signal statements
 * do on the real thing: the id and stamp match what the server reports, a
 * wrong stamp is left alone, a SQL Server login that reads dates day-first
 * still matches the stamp, KILL QUERY leaves the MySQL/MariaDB session alone.
 * The last two run the real QueryExecutor in a child process, as a queue
 * worker would, on a statement that would take minutes, and press Stop from
 * here: the child must be back within 5 seconds, the request must end
 * cancelled (not failed), and a stopped write must leave nothing behind.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\BackendCanceller;
use Illuminate\Support\Facades\DB;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    $this->it = IntegrationTarget::start(nums: true);
});

afterEach(function () {
    if (isset($this->it)) {
        $this->it->stop();
    }
});

/** Wait until the server no longer has session $id, up to $seconds. */
function itWaitForSessionGone(IntegrationTarget $it, int $id, float $seconds = 5, ?string $token = null): bool
{
    $deadline = microtime(true) + $seconds;

    while ($it->sessionExists($id, $token) && microtime(true) < $deadline) {
        usleep(100_000);
    }

    return ! $it->sessionExists($id, $token);
}

test('the session id and stamp read by the app are the ones the server reports', function () {
    $db = $this->it->session('probe');
    $pdo = $db->getPdo();

    $session = app(BackendCanceller::class)->identify($db, IntegrationTarget::driver());

    $expected = match (IntegrationTarget::driver()) {
        // CockroachDB has no pg_stat_activity rows; the app keeps the session_id as the stamp.
        DbDriver::Pgsql => IntegrationTarget::isCockroach()
            ? [(int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn(), 'crdb:'.$pdo->query('SHOW session_id')->fetchColumn()]
            : [
                (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn(),
                (string) $pdo->query('SELECT backend_start::text FROM pg_stat_activity WHERE pid = pg_backend_pid()')->fetchColumn(),
            ],
        DbDriver::Sqlsrv => [
            (int) $pdo->query('SELECT @@SPID')->fetchColumn(),
            (string) $pdo->query('SELECT CONVERT(varchar(30), login_time, 121) FROM sys.dm_exec_sessions WHERE session_id = @@SPID')->fetchColumn(),
        ],
        default => [(int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), null],
    };

    expect($session)->not->toBeNull()
        ->and($session['pid'])->toBe($expected[0])
        ->and($session['token'])->toBe($expected[1]);
});

test('a session id that now belongs to a different session is left alone, the right stamp stops it', function () {
    $driver = IntegrationTarget::driver();

    if (! in_array($driver, [DbDriver::Pgsql, DbDriver::Sqlsrv], true)) {
        $this->markTestSkipped('MySQL and MariaDB connection ids only grow, so the app records no stamp for them.');
    }

    $probe = $this->it->session('probe');
    $session = app(BackendCanceller::class)->identify($probe, $driver);
    $connection = $this->it->connection();
    $canceller = app(BackendCanceller::class);

    $wrongStamp = match (true) {
        IntegrationTarget::isCockroach() => 'crdb:'.str_repeat('0', 32),
        $driver === DbDriver::Pgsql => '2001-01-01 00:00:00.000000+00',
        default => '2001-01-01 00:00:00.000',
    };

    expect($canceller->cancel($connection, $session['pid'], $wrongStamp))->toBeFalse()
        ->and($this->it->sessionExists($session['pid'], $session['token']))->toBeTrue()
        ->and($canceller->cancel($connection, $session['pid'], $session['token']))->toBeTrue();

    if ($driver === DbDriver::Sqlsrv) {
        // KILL ends the whole session.
        expect(itWaitForSessionGone($this->it, $session['pid']))->toBeTrue();
    }
});

test('KILL QUERY ends the statement and leaves the MySQL or MariaDB session alone', function () {
    if (! IntegrationTarget::isMysqlFamily()) {
        $this->markTestSkipped('MySQL and MariaDB only.');
    }

    $probe = $this->it->session('probe');
    $session = app(BackendCanceller::class)->identify($probe, IntegrationTarget::driver());

    expect(app(BackendCanceller::class)->cancel($this->it->connection(), $session['pid']))->toBeTrue()
        ->and($this->it->sessionExists($session['pid']))->toBeTrue()
        ->and($probe->selectOne('SELECT 1 AS ok')->ok)->toBe(1);
});

test('a login that reads dates day-first still matches the stamp and stops the session', function () {
    if (IntegrationTarget::driver() !== DbDriver::Sqlsrv) {
        $this->markTestSkipped('SQL Server only: its login_time stamp is compared with CONVERT style 121.');
    }

    $login = 'qp_it_dmy_'.substr($this->it->suffix, -8);
    $password = 'Qp!Dmy_9z_login';

    $this->it->createSqlsrvLogin($login, $password, 'british');
    // Server-level permissions are granted from the master database.
    $database = IntegrationTarget::env('DATABASE', 'queryproxy_test');
    $this->it->admin()->unprepared("USE master; GRANT VIEW SERVER STATE TO {$login}; GRANT ALTER ANY CONNECTION TO {$login}; USE {$database};");

    $connection = $this->it->connection(['username' => $login, 'password' => $password]);

    // The premise: this login really is in a day-first language.
    $factory = app(DynamicConnectionFactory::class);
    $name = $factory->configure($connection);

    try {
        expect(DB::connection($name)->selectOne('SELECT @@LANGUAGE AS language')->language)->toContain('British');
    } finally {
        $factory->purge($connection);
    }

    $probe = $this->it->session('probe');
    $session = app(BackendCanceller::class)->identify($probe, DbDriver::Sqlsrv);

    expect(app(BackendCanceller::class)->cancel($connection, $session['pid'], $session['token']))->toBeTrue()
        ->and(itWaitForSessionGone($this->it, $session['pid']))->toBeTrue();
});

test('Stop ends a long read within 5 seconds and the request ends cancelled', function () {
    if (IntegrationTarget::isCockroach()) {
        $this->markTestSkipped('CockroachDB runs the query of a DECLARE CURSOR to completion before the first FETCH and ignores CANCEL QUERIES / CANCEL SESSION meanwhile, so a long read is not interrupted by Stop; the statement timeout ends it (next test). docs/compatibility.md.');
    }

    $request = $this->it->request($this->it->connection(), $this->it->heavyRead());
    $executor = $this->it->startExecutor($request);

    try {
        expect($this->it->waitUntilRunning($request, $executor))->toBeTrue($executor->getOutput().$executor->getErrorOutput());

        usleep(1_000_000); // let the statement actually start

        $running = QueryRequest::findOrFail($request->id);
        $pid = (int) $running->backend_pid;
        $stamp = $running->backend_session;
        $started = microtime(true);

        expect(app(ApprovalService::class)->stop($running, $this->it->owner))->toBeTrue();

        expect(IntegrationTarget::waitForExit($executor, 5))->toBeTrue()
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

        $request->refresh();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->error_message)->toBeNull()
            ->and($request->result_path)->toBeNull()
            ->and($request->backend_pid)->toBeNull()
            // The error the cancellation causes is not a failure of the request.
            ->and(AuditLog::where('query_request_id', $request->id)->whereIn('action', ['request.execution_failed', 'execution.late_completion'])->exists())->toBeFalse()
            ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'execution.cancelled')->sole()->metadata['signalled'])->toBeTrue()
            // The statement is gone from the server.
            ->and($this->it->waitUntilStatementGone($pid, $stamp))->toBeTrue('the statement is still on the server 5 seconds after the executor exited');
    } finally {
        if ($executor->isRunning()) {
            $executor->stop(1);
        }
    }
});

// CockroachDB only: what Stop does for a read there. The request is cancelled
// at once (that is the application's own state change), the statement itself
// runs on until the statement timeout cuts it, and nothing is stored.
test('CockroachDB: Stop marks a long read cancelled and the statement timeout ends the statement', function () {
    if (! IntegrationTarget::isCockroach()) {
        $this->markTestSkipped('CockroachDB only.');
    }

    $request = $this->it->request($this->it->connection(), $this->it->heavyRead());
    // Statement timeout = 12 - 5 = 7 seconds.
    $executor = $this->it->startExecutor($request, ['QUERYPROXY_EXECUTION_TIMEOUT' => '12']);

    try {
        expect($this->it->waitUntilRunning($request, $executor))->toBeTrue($executor->getOutput().$executor->getErrorOutput());

        usleep(1_000_000);

        $running = QueryRequest::findOrFail($request->id);
        $stamp = $running->backend_session;

        expect(app(ApprovalService::class)->stop($running, $this->it->owner))->toBeTrue();

        // The request is cancelled before the server has stopped anything.
        expect($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled);

        expect(IntegrationTarget::waitForExit($executor, 20))->toBeTrue('the statement timeout did not end the statement')
            ->and($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

        $request->refresh();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->error_message)->toBeNull()
            ->and($request->result_path)->toBeNull()
            ->and($this->it->waitUntilStatementGone(0, $stamp))->toBeTrue('the statement is still on the server 5 seconds after the executor exited');
    } finally {
        if ($executor->isRunning()) {
            $executor->stop(1);
        }
    }
});

test('Stop ends a long write within 5 seconds and nothing it was going to store is there', function () {
    $request = $this->it->request($this->it->connection(), $this->it->heavyWrite(), 'write');
    $executor = $this->it->startExecutor($request);

    try {
        expect($this->it->waitUntilRunning($request, $executor))->toBeTrue($executor->getOutput().$executor->getErrorOutput());

        usleep(1_000_000);

        $running = QueryRequest::findOrFail($request->id);
        $started = microtime(true);

        expect(app(ApprovalService::class)->stop($running, $this->it->owner))->toBeTrue();

        expect(IntegrationTarget::waitForExit($executor, 5))->toBeTrue()
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

        $request->refresh();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->error_message)->toBeNull()
            ->and(AuditLog::where('query_request_id', $request->id)->whereIn('action', ['request.execution_failed', 'execution.late_completion'])->exists())->toBeFalse()
            ->and($this->it->sinkCount())->toBe(0);
    } finally {
        if ($executor->isRunning()) {
            $executor->stop(1);
        }
    }
});

// CockroachDB only: the Stop statements (SHOW CLUSTER SESSIONS, CANCEL QUERIES)
// are looked at through the login's own privileges, and production logins are
// not administrators. Every other CockroachDB test runs as the administrator,
// so this one runs a write as a login that is not (CREATE ROLE ... LOGIN, with
// SELECT on the fixtures and INSERT on the sink only).
test('CockroachDB: Stop ends a long write run by a login without the admin role', function () {
    if (! IntegrationTarget::isCockroach()) {
        $this->markTestSkipped('CockroachDB only.');
    }

    $login = $this->it->reader();
    $admin = $this->it->admin();
    $admin->statement('GRANT INSERT ON '.$this->it->table('sink').' TO '.$login['username']);

    expect((int) $admin->scalar("SELECT count(*) FROM system.role_members WHERE role = 'admin' AND member = ?", [$login['username']]))
        ->toBe(0, 'the login must not be an administrator for this test to prove anything');

    $request = $this->it->request(
        $this->it->connection(['username' => $login['username'], 'password' => $login['password']]),
        $this->it->heavyWrite(),
        'write',
    );
    $executor = $this->it->startExecutor($request);

    try {
        expect($this->it->waitUntilRunning($request, $executor))->toBeTrue($executor->getOutput().$executor->getErrorOutput());

        usleep(1_000_000);

        $running = QueryRequest::findOrFail($request->id);
        $started = microtime(true);

        expect($running->backend_session)->toStartWith('crdb:')
            ->and(app(ApprovalService::class)->stop($running, $this->it->owner))->toBeTrue();

        expect(IntegrationTarget::waitForExit($executor, 5))->toBeTrue()
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

        $request->refresh();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->error_message)->toBeNull()
            ->and($this->it->sinkCount())->toBe(0);
    } finally {
        if ($executor->isRunning()) {
            $executor->stop(1);
        }
    }
});
