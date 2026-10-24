<?php

/*
 * Database-side timeouts on a real server (Faz 01): the statement timeout is
 * derived from QUERYPROXY_EXECUTION_TIMEOUT (minus 5 seconds) and the lock
 * timeout from QUERYPROXY_LOCK_TIMEOUT, and the server itself cuts a statement
 * that overruns them, long before the queue job would be killed.
 *
 * The long statements are a three-way cross join of a 3000-row table: they run
 * for minutes on every server and can be interrupted. A request that outlives
 * its statement timeout must come back Failed within seconds. The executor runs
 * in a child process with a hard limit, so a timeout that is not enforced fails
 * the test instead of hanging the job.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
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

/** What the server (or the driver) says when the statement timeout cuts a statement. */
function itStatementTimeoutMessage(): string
{
    return match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => '/statement timeout/i',
        DbDriver::Mysql => '/maximum statement execution time exceeded/i',
        DbDriver::Mariadb => '/max_statement_time exceeded/i',
        default => '/time ?out/i',
    };
}

/** What the server says when a statement gave up waiting for a lock. */
function itLockTimeoutMessage(): string
{
    return match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => '/lock timeout/i',
        DbDriver::Sqlsrv => '/lock request time out/i',
        default => '/lock wait timeout exceeded/i',
    };
}

/**
 * Run the request in a child process that must be done within 40 seconds.
 *
 * @param  array<string, string>  $env
 * @return array{request: QueryRequest, seconds: float}
 */
function itRunWithin40Seconds(IntegrationTarget $it, QueryRequest $request, array $env): array
{
    $started = microtime(true);
    $executor = $it->startExecutor($request, $env, timeout: 40);
    $executor->wait();

    expect($executor->getExitCode())->toBe(0, $executor->getOutput().$executor->getErrorOutput());

    return ['request' => $request->fresh(), 'seconds' => microtime(true) - $started];
}

test('the statement timeout cuts a long read on the server', function () {
    // Statement timeout = 7 - 5 = 2 seconds.
    $run = itRunWithin40Seconds($this->it, $this->it->request($this->it->connection(), $this->it->heavyRead()), ['QUERYPROXY_EXECUTION_TIMEOUT' => '7']);

    expect($run['request']->status)->toBe(QueryRequestStatus::Failed)
        ->and($run['request']->error_message)->toMatch(itStatementTimeoutMessage())
        // Two seconds of statement plus the child's own start-up; nowhere near the minutes the statement needs.
        ->and($run['seconds'])->toBeLessThan(15.0)
        ->and($run['request']->backend_pid)->toBeNull();
});

test('the statement timeout cuts a long write on the server and nothing is stored', function () {
    if (IntegrationTarget::driver() === DbDriver::Mysql) {
        $this->markTestSkipped('MySQL\'s MAX_EXECUTION_TIME only applies to SELECT statements, so a write is not cut by the server (it ends at the job timeout).');
    }

    $run = itRunWithin40Seconds($this->it, $this->it->request($this->it->connection(), $this->it->heavyWrite(), 'write'), ['QUERYPROXY_EXECUTION_TIMEOUT' => '7']);

    expect($run['request']->status)->toBe(QueryRequestStatus::Failed)
        ->and($run['request']->error_message)->toMatch(itStatementTimeoutMessage())
        ->and($run['seconds'])->toBeLessThan(15.0)
        ->and($this->it->sinkCount())->toBe(0);
});

test('the lock timeout gives up on a row locked by another session', function () {
    $holder = $this->it->session('holder');
    $holder->beginTransaction();

    try {
        $holder->update('UPDATE '.$this->it->table('items')." SET label = 'held' WHERE id = 1");

        $run = itRunWithin40Seconds($this->it, $this->it->request($this->it->connection(), "UPDATE {items} SET label = 'writer' WHERE id = 1", 'write'), ['QUERYPROXY_LOCK_TIMEOUT' => '1']);

        expect($run['request']->status)->toBe(QueryRequestStatus::Failed)
            ->and($run['request']->error_message)->toMatch(itLockTimeoutMessage())
            ->and($run['seconds'])->toBeLessThan(15.0);
    } finally {
        $holder->rollBack();
    }

    expect($this->it->itemLabel(1))->toBe('one');
});
