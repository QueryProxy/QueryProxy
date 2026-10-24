<?php

/*
 * Live PostgreSQL checks for stopping a running query.
 *
 * The unit suite proves which signal is sent per driver; this file proves a
 * real server honours it: a session sitting in pg_sleep(30) is stopped
 * through ApprovalService::stop() (the Stop button) within 5 seconds, the
 * request ends up cancelled, and the session itself reports that the
 * statement was cancelled by request. The last test runs the real
 * QueryExecutor in a child process, as a queue worker would, and stops it
 * from here.
 *
 * The application database is a throwaway SQLite file, so the child process
 * and the test see the same rows.
 *
 * Runs only when QUERYPROXY_LIVE_PGSQL_HOST is set (group "live-pgsql"); see
 * PgsqlGuardTest.php for the docker compose setup.
 */

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Execution\BackendCanceller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Live\LivePgsql;

const CANCEL_APP_DB = 'live_cancel_app';

beforeEach(function () {
    if (! LivePgsql::configured()) {
        $this->markTestSkipped('Set '.LivePgsql::ENV_PREFIX.'HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live PostgreSQL cancel tests.');
    }

    $this->live = LivePgsql::start();

    $this->originalDefault = DB::getDefaultConnection();
    $this->appDbFile = tempnam(sys_get_temp_dir(), 'qp_live_cancel_').'.sqlite';
    touch($this->appDbFile);

    config(['database.connections.'.CANCEL_APP_DB => [
        'driver' => 'sqlite',
        'database' => $this->appDbFile,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection(CANCEL_APP_DB);
    Artisan::call('migrate', ['--database' => CANCEL_APP_DB, '--force' => true]);
});

afterEach(function () {
    if (isset($this->live)) {
        $this->live->stop();
    }

    if (isset($this->originalDefault)) {
        DB::purge(CANCEL_APP_DB);
        DB::setDefaultConnection($this->originalDefault);
        @unlink($this->appDbFile);
    }
});

/** A Connection row that points at the live server with the live credentials. */
function liveCancelConnection(Team $team): Connection
{
    $env = fn (string $key, string $default = ''): string => (getenv(LivePgsql::ENV_PREFIX.$key) ?: $default);

    return Connection::factory()->create([
        'team_id' => $team->id,
        'driver' => DbDriver::Pgsql,
        'host' => $env('HOST'),
        'port' => $env('PORT', '5432'),
        'database' => $env('DATABASE', 'postgres'),
        'username' => $env('USERNAME', 'postgres'),
        'password' => $env('PASSWORD'),
        // A new connection requires TLS (ADR-019); the throwaway server has none.
        'options' => ['tls' => ['mode' => 'disable']],
    ]);
}

/** @return array{Team, User} */
function liveCancelTeam(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->users()->attach($owner, ['role' => 'developer']);

    return [$team, $owner];
}

/**
 * Start the sleeper and wait until it has reported its backend PID and start stamp.
 *
 * @return array{Process, string, int, string} the process, the output so far, pid and stamp
 */
function liveCancelStartSleeper(): array
{
    $sleeper = new Process([PHP_BINARY, base_path('tests/Live/pgsql_sleeper.php'), '30'], base_path());
    $sleeper->setTimeout(60)->start();

    $output = '';
    $deadline = microtime(true) + 10;

    while (! (preg_match('/^PID (\d+)$/m', $output, $pid) && preg_match('/^STAMP (.+)$/m', $output, $stamp)) && microtime(true) < $deadline) {
        usleep(50_000);
        $output .= $sleeper->getIncrementalOutput();
    }

    expect($pid[1] ?? null)->not->toBeNull($sleeper->getErrorOutput().$output);

    // Give the statement a moment to be running rather than about to start.
    usleep(500_000);

    return [$sleeper, $output, (int) $pid[1], $stamp[1]];
}

test('Cancel: the session id and stamp read by the app are the ones the server reports', function () {
    $session = app(BackendCanceller::class)->identify($this->live->db(), DbDriver::Pgsql);

    expect($session['pid'])->toBe($this->live->backendPid())
        ->and($session['token'])->toBe((string) $this->live->db()->selectOne('SELECT backend_start::text AS stamp FROM pg_stat_activity WHERE pid = pg_backend_pid()')->stamp);
});

test('Cancel: Stop cancels a pg_sleep(30) statement within 5 seconds and the request ends cancelled', function () {
    [$sleeper, $output, $pid, $stamp] = liveCancelStartSleeper();

    try {
        [$team, $owner] = liveCancelTeam();

        $request = QueryRequest::factory()->create([
            'team_id' => $team->id,
            'connection_id' => liveCancelConnection($team)->id,
            'user_id' => $owner->id,
            'sql_original' => 'SELECT pg_sleep(30)',
            'sql_prepared' => 'SELECT pg_sleep(30)',
            'status' => QueryRequestStatus::Running,
            'executed_at' => now(),
            'backend_pid' => $pid,
            'backend_session' => $stamp,
        ]);

        $started = microtime(true);

        expect(app(ApprovalService::class)->stop($request, $owner))->toBeTrue();

        // The sleeper's own session reports the cancellation.
        $deadline = microtime(true) + 5;

        while ($sleeper->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $output .= $sleeper->getIncrementalOutput();

        expect($sleeper->isRunning())->toBeFalse()
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($output)->toContain('ERROR 57014')
            ->and(strtolower($output))->toContain('user request');

        $request->refresh();
        $log = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.cancelled')->sole();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->backend_pid)->toBeNull()
            ->and($log->metadata['signalled'])->toBeTrue();
    } finally {
        if ($sleeper->isRunning()) {
            $sleeper->stop(1);
        }
    }
});

test('Cancel: a session id that now belongs to a different session is left alone', function () {
    [$sleeper, , $pid] = liveCancelStartSleeper();

    try {
        [$team, $owner] = liveCancelTeam();

        // The recorded stamp is of an earlier session that had this pid.
        $request = QueryRequest::factory()->create([
            'team_id' => $team->id,
            'connection_id' => liveCancelConnection($team)->id,
            'user_id' => $owner->id,
            'status' => QueryRequestStatus::Running,
            'executed_at' => now(),
            'backend_pid' => $pid,
            'backend_session' => '2001-01-01 00:00:00.000000+00',
        ]);

        expect(app(ApprovalService::class)->stop($request, $owner))->toBeFalse();

        usleep(500_000);

        expect($sleeper->isRunning())->toBeTrue()
            ->and($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled);
    } finally {
        $sleeper->stop(1);
    }
});

test('Cancel: Stop on a request the real executor is running cancels pg_sleep(30) within 5 seconds', function () {
    [$team, $owner] = liveCancelTeam();
    $resultDir = sys_get_temp_dir().'/qp_live_cancel_results_'.bin2hex(random_bytes(4));

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => liveCancelConnection($team)->id,
        'user_id' => $owner->id,
        'sql_original' => 'SELECT pg_sleep(30)',
        'sql_prepared' => 'SELECT pg_sleep(30)',
        'type' => 'read',
        'status' => QueryRequestStatus::Queued,
    ]);

    $executor = new Process([PHP_BINARY, base_path('tests/Live/pgsql_executor.php'), (string) $request->id, $resultDir], base_path(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $this->appDbFile,
        'DB_URL' => '',
    ]);
    $executor->setTimeout(60)->start();

    try {
        // Wait until the executor has started the statement and recorded its session.
        $deadline = microtime(true) + 15;

        do {
            usleep(100_000);
            $running = QueryRequest::whereKey($request->id)->where('status', QueryRequestStatus::Running)->whereNotNull('backend_pid')->exists();
        } while (! $running && $executor->isRunning() && microtime(true) < $deadline);

        expect($running)->toBeTrue($executor->getOutput().$executor->getErrorOutput());

        usleep(500_000); // let pg_sleep actually start

        $pid = (int) QueryRequest::whereKey($request->id)->value('backend_pid');
        $started = microtime(true);

        expect(app(ApprovalService::class)->stop(QueryRequest::find($request->id), $owner))->toBeTrue();

        $deadline = microtime(true) + 5;

        while ($executor->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        expect($executor->isRunning())->toBeFalse()
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

        $request->refresh();

        expect($request->status)->toBe(QueryRequestStatus::Cancelled)
            ->and($request->error_message)->toBeNull()
            ->and($request->result_path)->toBeNull()
            // The error the cancellation causes is not a failure of the request.
            ->and(AuditLog::where('query_request_id', $request->id)->whereIn('action', ['request.execution_failed', 'execution.late_completion'])->exists())->toBeFalse()
            ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'execution.cancelled')->count())->toBe(1);

        // The statement is gone from the server: no active session still runs it.
        $active = $this->live->db()->selectOne(
            "SELECT count(*) AS n FROM pg_stat_activity WHERE pid = ? AND state <> 'idle'",
            [$pid],
        )->n;

        expect((int) $active)->toBe(0);
    } finally {
        if ($executor->isRunning()) {
            $executor->stop(1);
        }
    }
});
