<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Jobs\Middleware\LimitConnectionConcurrency;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/**
 * Per-connection slot limit: ExecuteQueryRequest runs through
 * LimitConnectionConcurrency, which holds a `conn:{id}:slot:{n}` cache lock
 * while a request executes and puts the job back on the queue when all N
 * slots of the connection are busy. The tests drive the middleware directly,
 * nesting calls to stand in for concurrent workers.
 */
function slotRequest(?Connection $connection = null, QueryRequestStatus $status = QueryRequestStatus::Queued): QueryRequest
{
    $team = $connection?->team ?? Team::factory()->create();
    $connection ??= Connection::factory()->create(['team_id' => $team->id]);

    return QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'status' => $status,
    ]);
}

/** @return array{0: ExecuteQueryRequest, 1: MockInterface} */
function slotJob(QueryRequest $request, bool $expectDelete = false): array
{
    $job = new ExecuteQueryRequest($request);
    $queueJob = Mockery::mock(Job::class);

    if ($expectDelete) {
        $queueJob->shouldReceive('delete')->once();
    } else {
        $queueJob->shouldNotReceive('delete');
    }

    $job->setJob($queueJob);

    return [$job, $queueJob];
}

beforeEach(function () {
    config(['queryproxy.connection_slots' => 2, 'queryproxy.connection_slot_retry_delay' => 7]);
    Cache::flush();
});

test('the job goes through the slot middleware', function () {
    $job = new ExecuteQueryRequest(slotRequest());

    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(LimitConnectionConcurrency::class);
});

test('of three concurrent runs on one connection the first two execute and the third is re-queued', function () {
    Queue::fake();

    $connection = Connection::factory()->create();
    $middleware = new LimitConnectionConcurrency;
    $ran = [];

    $run = function (string $name, Closure $inside) use ($connection, $middleware, &$ran) {
        [$job] = slotJob(slotRequest($connection), expectDelete: $name === 'third');

        $middleware->handle($job, function () use ($name, $inside, &$ran) {
            $ran[] = $name;
            $inside();
        });
    };

    $run('first', fn () => $run('second', fn () => $run('third', fn () => null)));

    expect($ran)->toBe(['first', 'second']);

    Queue::assertPushed(ExecuteQueryRequest::class, 1);
    Queue::assertPushed(ExecuteQueryRequest::class, fn (ExecuteQueryRequest $copy) => $copy->delay === 7 && $copy->queue === 'queries');
});

test('a slot is free again as soon as its run is over', function () {
    Queue::fake();

    $connection = Connection::factory()->create();
    $middleware = new LimitConnectionConcurrency;
    $ran = 0;

    foreach (range(1, 5) as $ignored) {
        [$job] = slotJob(slotRequest($connection));
        $middleware->handle($job, function () use (&$ran) {
            $ran++;
        });
    }

    expect($ran)->toBe(5);
    Queue::assertNothingPushed();
});

test('a slot is released even when the run throws', function () {
    Queue::fake();

    $connection = Connection::factory()->create();
    $middleware = new LimitConnectionConcurrency;

    config(['queryproxy.connection_slots' => 1]);

    [$job] = slotJob(slotRequest($connection));

    expect(fn () => $middleware->handle($job, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    $ran = false;
    [$next] = slotJob(slotRequest($connection));
    $middleware->handle($next, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

test('slots are counted per connection', function () {
    Queue::fake();

    $busy = Connection::factory()->create();
    $other = Connection::factory()->create();
    $middleware = new LimitConnectionConcurrency;

    $held = [
        Cache::lock(LimitConnectionConcurrency::lockName($busy->id, 1), 60),
        Cache::lock(LimitConnectionConcurrency::lockName($busy->id, 2), 60),
    ];

    foreach ($held as $lock) {
        expect($lock->get())->toBeTrue();
    }

    $ranOnOther = false;
    [$job] = slotJob(slotRequest($other));
    $middleware->handle($job, function () use (&$ranOnOther) {
        $ranOnOther = true;
    });

    $ranOnBusy = false;
    [$blocked] = slotJob(slotRequest($busy), expectDelete: true);
    $middleware->handle($blocked, function () use (&$ranOnBusy) {
        $ranOnBusy = true;
    });

    expect($ranOnOther)->toBeTrue()
        ->and($ranOnBusy)->toBeFalse();
    Queue::assertPushed(ExecuteQueryRequest::class, 1);
});

test('the number of slots comes from the configuration', function () {
    Queue::fake();

    config(['queryproxy.connection_slots' => 1]);

    $connection = Connection::factory()->create();
    $middleware = new LimitConnectionConcurrency;
    $ran = [];

    [$first] = slotJob(slotRequest($connection));
    [$second] = slotJob(slotRequest($connection), expectDelete: true);

    $middleware->handle($first, function () use ($middleware, $second, &$ran) {
        $ran[] = 'first';
        $middleware->handle($second, function () use (&$ran) {
            $ran[] = 'second';
        });
    });

    expect($ran)->toBe(['first']);
});

test('a limit of zero turns the slots off', function () {
    Queue::fake();

    config(['queryproxy.connection_slots' => 0]);

    $middleware = new LimitConnectionConcurrency;
    $connection = Connection::factory()->create();
    $depth = 0;

    $run = function () use (&$run, &$depth, $middleware, $connection) {
        [$job] = slotJob(slotRequest($connection));
        $middleware->handle($job, function () use (&$run, &$depth) {
            if (++$depth < 4) {
                $run();
            }
        });
    };
    $run();

    expect($depth)->toBe(4);
    Queue::assertNothingPushed();
});

test('a request that is no longer waiting is not parked', function (QueryRequestStatus $status) {
    Queue::fake();

    $connection = Connection::factory()->create();

    foreach ([1, 2] as $slot) {
        Cache::lock(LimitConnectionConcurrency::lockName($connection->id, $slot), 60)->get();
    }

    $ran = false;
    [$job] = slotJob(slotRequest($connection, $status));
    (new LimitConnectionConcurrency)->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
    Queue::assertNothingPushed();
})->with([QueryRequestStatus::Cancelled, QueryRequestStatus::Running, QueryRequestStatus::Completed]);

test('the sync queue driver is never limited, since it could not come back later', function () {
    Queue::fake();

    $connection = Connection::factory()->create();

    foreach ([1, 2] as $slot) {
        Cache::lock(LimitConnectionConcurrency::lockName($connection->id, $slot), 60)->get();
    }

    $job = new ExecuteQueryRequest(slotRequest($connection));
    $job->setJob(new SyncJob(app(), '{}', 'sync', 'queries'));

    $ran = false;
    (new LimitConnectionConcurrency)->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

test('a deferred request stays queued and its history says why, once', function () {
    Queue::fake();
    Log::spy();

    $connection = Connection::factory()->create();
    $request = slotRequest($connection);

    foreach ([1, 2] as $slot) {
        Cache::lock(LimitConnectionConcurrency::lockName($connection->id, $slot), 60)->get();
    }

    $middleware = new LimitConnectionConcurrency;

    foreach ([1, 2, 3] as $ignored) {
        [$job] = slotJob($request, expectDelete: true);
        $middleware->handle($job, fn () => throw new LogicException('must not run'));
    }

    $entries = AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_deferred')->get();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->metadata)->toMatchArray(['reason' => 'connection_slots_busy', 'slots' => 2]);
    Queue::assertPushed(ExecuteQueryRequest::class, 3);

    // One info line for the first deferral; the retries that follow stay at debug level.
    Log::shouldHaveReceived('info')->once();
    Log::shouldHaveReceived('debug')->twice();
});

test('requeue puts a fresh copy on the same queue after the delay and deletes the current one', function () {
    Queue::fake();

    [$job] = slotJob(slotRequest(), expectDelete: true);
    $job->requeue(9);

    Queue::assertPushed(ExecuteQueryRequest::class, fn (ExecuteQueryRequest $copy) => $copy !== $job
        && $copy->delay === 9
        && $copy->queue === 'queries'
        && $copy->request->is($job->request));
});
