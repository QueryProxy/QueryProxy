<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Providers\AppServiceProvider;
use App\Services\Audit\AuditRecorder;
use App\Services\Execution\QueryExecutor;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Evaluate config/queue.php with the given env overrides (null = unset),
 * restoring the original environment afterwards.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function loadQueueConfig(array $env): array
{
    $original = [];

    foreach ($env as $key => $value) {
        $original[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return require config_path('queue.php');
    } finally {
        foreach ($original as $key => [$server, $envValue, $putenv]) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($envValue !== null) {
                $_ENV[$key] = $envValue;
            }

            if ($putenv !== false) {
                putenv("{$key}={$putenv}");
            }
        }
    }
}

/**
 * Same fixture as QueryExecutionTest, kept local so this file runs on its own:
 * a SQLite target database with three customers and a queued request.
 *
 * @return array{0: QueryRequest, 1: string, 2: Team}
 */
function timingSetup(string $sql, array $overrides = []): array
{
    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_timing_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO customers (name) VALUES ('Ada'), ('Grace'), ('Alan')");
    unset($pdo);

    $team = Team::factory()->create();
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id]);

    $request = QueryRequest::factory()->create(array_merge([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
        'status' => QueryRequestStatus::Queued,
    ], $overrides));

    return [$request, $dbFile, $team];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_timing_*') ?: [] as $file) {
        @unlink($file);
    }
});

/**
 * @return array<string, null>
 */
function unsetRetryAfterEnv(): array
{
    return [
        'DB_QUEUE_RETRY_AFTER' => null,
        'REDIS_QUEUE_RETRY_AFTER' => null,
        'BEANSTALKD_QUEUE_RETRY_AFTER' => null,
    ];
}

test('retry_after defaults above the execution timeout when no env value is set', function () {
    $queue = loadQueueConfig(unsetRetryAfterEnv());
    $timeout = (int) config('queryproxy.execution_timeout');

    expect($queue['connections']['database']['retry_after'])->toBeGreaterThan($timeout)
        ->and($queue['connections']['redis']['retry_after'])->toBeGreaterThan($timeout)
        ->and($queue['connections']['beanstalkd']['retry_after'])->toBeGreaterThan($timeout)
        ->and($queue['connections']['database']['retry_after'])->toBe($timeout + 30);
});

test('retry_after follows a changed execution timeout', function () {
    config(['queryproxy.execution_timeout' => 900]);

    $queue = loadQueueConfig(unsetRetryAfterEnv());

    expect($queue['connections']['database']['retry_after'])->toBe(930)
        ->and($queue['connections']['redis']['retry_after'])->toBe(930);
});

test('an explicit retry_after env value wins', function () {
    $queue = loadQueueConfig([...unsetRetryAfterEnv(), 'DB_QUEUE_RETRY_AFTER' => '999']);

    expect($queue['connections']['database']['retry_after'])->toBe(999);
});

test('the loaded application config has a safe retry_after', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan(config('queryproxy.execution_timeout'))
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan(config('queryproxy.execution_timeout'));
});

/**
 * Flip the request to Failed the moment the target database runs the query,
 * i.e. after the executor claimed it but before it writes any result — what
 * the job's failed() handler does once retry_after expires mid-query.
 */
function failRequestDuringExecution(QueryRequest $request): void
{
    $flipped = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($request, &$flipped) {
        if ($flipped || $event->connectionName === config('database.default')) {
            return;
        }

        $flipped = true;

        QueryRequest::whereKey($request->id)->update([
            'status' => QueryRequestStatus::Failed,
            'error_message' => 'Job timed out.',
        ]);
    });
}

test('a read finishing after the request was failed leaves it failed and discards the result', function () {
    [$request, , $team] = timingSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');
    failRequestDuringExecution($request);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toBe('Job timed out.')
        ->and($request->result_path)->toBeNull();

    Storage::disk('local')->assertMissing(sprintf('results/%d/%d.ndjson', $team->id, $request->id));

    $late = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.late_completion')->first();

    expect($late)->not->toBeNull()
        ->and($late->metadata['status'])->toBe('failed')
        ->and($late->metadata['result_discarded'])->toBeTrue()
        ->and($late->metadata)->toHaveKey('duration_ms')
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_completed')->exists())->toBeFalse();
});

test('a late read is still audited when its result cannot be deleted', function (bool $throws) {
    [$request] = timingSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');
    failRequestDuringExecution($request);

    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $throws
        ? $disk->shouldReceive('delete')->andThrow(new RuntimeException('object store unavailable'))
        : $disk->shouldReceive('delete')->andReturn(false);
    Storage::set('local', $disk);

    Log::spy();

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $late = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.late_completion')->first();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->result_path)->toBeNull()
        ->and($late)->not->toBeNull()
        ->and($late->metadata['status'])->toBe('failed')
        ->and($late->metadata['result_discarded'])->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'could not be deleted'))
        ->once();
    Log::shouldNotHaveReceived('error');
})->with([
    'delete throws' => [true],
    'delete returns false (throw => false disks)' => [false],
]);

test('a write finishing after the request was failed records that it was committed', function () {
    [$request] = timingSetup('UPDATE customers SET active = 0 WHERE id > 1', ['type' => 'write']);
    failRequestDuringExecution($request);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $late = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.late_completion')->first();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->affected_rows)->toBeNull()
        ->and($late->metadata['write_committed'])->toBeTrue()
        ->and($late->metadata['affected_rows'])->toBe(2)
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_completed')->exists())->toBeFalse();
});

test('a driver error after the request was failed keeps the original failure', function () {
    [$request] = timingSetup('UPDATE customers SET active = 0 WHERE id > 1; UPDATE missing_table SET x = 1', ['type' => 'write']);
    failRequestDuringExecution($request);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $late = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.late_completion')->first();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toBe('Job timed out.')
        ->and($request->duration_ms)->toBeNull()
        ->and($late->metadata['failed'])->toBeTrue()
        ->and($late->metadata['status'])->toBe('failed')
        ->and($late->metadata['error'])->toContain('missing_table')
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_failed')->exists())->toBeFalse();
});

test('an error after the completion transition is not recorded as a late failure', function () {
    [$request] = timingSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');

    $recorder = Mockery::mock(AuditRecorder::class)->makePartial();
    $recorder->shouldReceive('record')
        ->with('request.execution_completed', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
        ->andThrow(new RuntimeException('audit store unavailable'));
    app()->instance(AuditRecorder::class, $recorder);

    Log::spy();

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->error_message)->toBeNull()
        ->and(AuditLog::where('query_request_id', $request->id)
            ->whereIn('action', ['execution.late_completion', 'request.execution_failed'])
            ->exists())->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'recording its outcome failed'))
        ->once();
});

test('a normal run completes the request and records the completion', function () {
    [$request] = timingSetup('SELECT id, name FROM customers ORDER BY id LIMIT 10');

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->result_columns)->toBe(['id', 'name'])
        ->and($request->result_truncated)->toBeFalse();

    Storage::disk('local')->assertExists($request->result_path);

    $completed = AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_completed')->first();

    expect($completed)->not->toBeNull()
        ->and($completed->metadata['result_rows'])->toBe(3)
        ->and(AuditLog::where('action', 'execution.late_completion')->exists())->toBeFalse();
});

function startWorker(string $connection): void
{
    event(new WorkerStarting($connection, 'queries', new WorkerOptions));
}

test('a starting worker warns when its retry_after does not exceed the execution timeout', function () {
    config([
        'queue.connections.database.retry_after' => 300,
        'queryproxy.execution_timeout' => 300,
    ]);

    Log::spy();

    startWorker('database');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'retry_after') && $context['queue_connection'] === 'database')
        ->once();
});

test('a starting worker stays quiet with a safe retry_after or a queue without one', function (string $connection, ?int $retryAfter) {
    config([
        "queue.connections.{$connection}.retry_after" => $retryAfter,
        'queryproxy.execution_timeout' => 300,
    ]);

    Log::spy();

    startWorker($connection);

    Log::shouldNotHaveReceived('warning');
})->with([
    'database 330' => ['database', 330],
    'sync' => ['sync', null],
]);

test('booting outside a queue worker never checks the timing', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 30,
        'queryproxy.execution_timeout' => 300,
    ]);

    Log::spy();

    app()->getProvider(AppServiceProvider::class)->boot();

    Log::shouldNotHaveReceived('warning');
});

/**
 * Raise CommandStarting the way the console kernel does: with the input
 * already bound to the real command's definition.
 *
 * @param  array<string, mixed>  $parameters
 */
function startCommand(string $name, array $parameters = []): void
{
    $input = new ArrayInput($parameters, Artisan::all()[$name]->getDefinition());

    event(new CommandStarting($name, $input, new BufferedOutput));
}

function forgetListenerChildMarker(): void
{
    unset($_SERVER['QUERYPROXY_QUEUE_LISTENER_CHILD'], $_ENV['QUERYPROXY_QUEUE_LISTENER_CHILD']);
    putenv('QUERYPROXY_QUEUE_LISTENER_CHILD');
}

function unsafeQueueTiming(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 300,
        'queue.connections.redis.retry_after' => 300,
        'queryproxy.execution_timeout' => 300,
    ]);
}

function assertTimingWarnedOnceFor(string $connection): void
{
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'retry_after') && $context['queue_connection'] === $connection)
        ->once();
}

describe('command-started queue processes', function () {
    beforeEach(fn () => forgetListenerChildMarker());
    afterEach(fn () => forgetListenerChildMarker());

    test('they warn at start with an unsafe retry_after', function (string $command, array $parameters, string $connection) {
        unsafeQueueTiming();

        Log::spy();

        startCommand($command, $parameters);

        assertTimingWarnedOnceFor($connection);
    })->with([
        'queue:listen, default connection' => ['queue:listen', [], 'database'],
        'queue:listen redis' => ['queue:listen', ['connection' => 'redis'], 'redis'],
        'queue:work --once, default connection' => ['queue:work', ['--once' => true], 'database'],
        'queue:work redis --once' => ['queue:work', ['connection' => 'redis', '--once' => true], 'redis'],
    ]);

    test('they stay quiet with a safe retry_after', function (string $command, array $parameters) {
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 330,
            'queryproxy.execution_timeout' => 300,
        ]);

        Log::spy();

        startCommand($command, $parameters);

        Log::shouldNotHaveReceived('warning');
    })->with([
        'queue:listen' => ['queue:listen', []],
        'queue:work --once' => ['queue:work', ['--once' => true]],
    ]);

    test('queue:work --once warns only once per process', function () {
        unsafeQueueTiming();

        Log::spy();

        startCommand('queue:work', ['--once' => true]);
        startWorker('database');

        assertTimingWarnedOnceFor('database');
    });

    test('queue:listen marks its --once children so they do not repeat the warning', function () {
        unsafeQueueTiming();

        startCommand('queue:listen');

        expect(getenv('QUERYPROXY_QUEUE_LISTENER_CHILD'))->toBe('1')
            ->and($_SERVER['QUERYPROXY_QUEUE_LISTENER_CHILD'] ?? null)->toBe('1');

        // A fresh application stands in for the child process: new provider,
        // inherited environment.
        $this->refreshApplication();
        unsafeQueueTiming();

        Log::spy();

        startCommand('queue:work', ['--once' => true]);

        Log::shouldNotHaveReceived('warning');
    });

    test('unrelated commands and a plain queue:work start never check the timing', function (string $command, array $parameters) {
        unsafeQueueTiming();

        Log::spy();

        startCommand($command, $parameters);

        Log::shouldNotHaveReceived('warning');
    })->with([
        'migrate' => ['migrate', []],
        'queue:work daemon (left to WorkerStarting)' => ['queue:work', []],
    ]);
});
