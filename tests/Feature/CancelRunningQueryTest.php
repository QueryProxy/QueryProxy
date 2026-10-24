<?php

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Requests\Show;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestFinished;
use App\Notifications\QueryRequestStopped;
use App\Services\Approvals\ApprovalService;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\BackendCanceller;
use App\Services\Execution\QueryExecutor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Stopping a running query: who may, what changes, what is audited, and what
 * the executor does when its statement is cancelled underneath it. The
 * database-side signal is a mock here (BackendCanceller); its own statements
 * per driver are covered at the bottom.
 *
 * @return array{Team, User, User, User, User, QueryRequest}
 */
function stopSetup(array $overrides = []): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $dba = User::factory()->create();
    $colleague = User::factory()->create();
    $auditor = User::factory()->create();
    $team->users()->attach($owner, ['role' => 'developer']);
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($colleague, ['role' => 'developer']);
    $team->users()->attach($auditor, ['role' => 'auditor']);

    $request = QueryRequest::factory()->create($overrides + [
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $owner->id,
        'status' => QueryRequestStatus::Running,
        'executed_at' => now()->subSeconds(3),
        'backend_pid' => 4242,
    ]);

    session(['current_team_id' => $team->id]);

    return [$team, $owner, $dba, $colleague, $auditor, $request];
}

function mockCanceller(bool $signalled = true, int $times = 1): void
{
    test()->mock(BackendCanceller::class, function ($mock) use ($signalled, $times) {
        $mock->shouldReceive('cancel')->times($times)->andReturn($signalled);
    });
}

test('Cancel: the requester, the team dba and an admin may stop a running request', function (string $who) {
    mockCanceller();
    [, $owner, $dba, , , $request] = stopSetup();
    $actor = match ($who) {
        'requester' => $owner,
        'dba' => $dba,
        'admin' => User::factory()->create(['is_admin' => true]),
    };

    expect($actor->can('stop', $request))->toBeTrue()
        ->and(app(ApprovalService::class)->stop($request, $actor))->toBeTrue();

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($request->backend_pid)->toBeNull();
})->with(['requester', 'dba', 'admin']);

test('Cancel: stopping signals the connection with the recorded session id and writes execution.cancelled', function () {
    [, , $dba, , , $request] = stopSetup();

    test()->mock(BackendCanceller::class, function ($mock) use ($request) {
        $mock->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (Connection $connection, int $pid) => $connection->is($request->connection) && $pid === 4242)
            ->andReturn(true);
    });

    app(ApprovalService::class)->stop($request, $dba);

    $log = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.cancelled')->sole();

    expect($log->user_id)->toBe($dba->id)
        ->and($log->metadata['signalled'])->toBeTrue()
        ->and($log->metadata['duration_ms'])->toBeGreaterThanOrEqual(3000)
        ->and($log->metadata)->not->toHaveKey('actor');
});

test('Cancel: nobody else may stop it and nothing changes when they try', function (string $who) {
    mockCanceller(times: 0);
    [, , , $colleague, $auditor, $request] = stopSetup();
    $actor = match ($who) {
        'teammate' => $colleague,
        'auditor' => $auditor,
        'outsider' => User::factory()->create(),
    };

    expect($actor->can('stop', $request))->toBeFalse()
        ->and(fn () => app(ApprovalService::class)->stop($request, $actor))->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Running)
        ->and($request->fresh()->backend_pid)->toBe(4242)
        ->and(AuditLog::where('action', 'execution.cancelled')->exists())->toBeFalse();
})->with(['teammate', 'auditor', 'outsider']);

test('Cancel: only a running request with a known session can be stopped, admin included', function (QueryRequestStatus $status, ?int $pid) {
    mockCanceller(times: 0);
    [, $owner, , , , $request] = stopSetup(['status' => $status, 'backend_pid' => $pid]);
    $admin = User::factory()->create(['is_admin' => true]);

    expect($owner->can('stop', $request))->toBeFalse()
        ->and($admin->can('stop', $request))->toBeFalse()
        ->and(fn () => app(ApprovalService::class)->stop($request, $admin))->toThrow(AuthorizationException::class);
})->with([
    'running, session not recorded yet' => [QueryRequestStatus::Running, null],
    'queued' => [QueryRequestStatus::Queued, null],
    'pending' => [QueryRequestStatus::Pending, null],
    'completed' => [QueryRequestStatus::Completed, null],
    'failed' => [QueryRequestStatus::Failed, null],
    'cancelled' => [QueryRequestStatus::Cancelled, null],
]);

test('Cancel: a stop on a stale copy of the request is refused once the run moved on', function () {
    mockCanceller(times: 0);
    [, $owner, , , , $request] = stopSetup();

    // The run finished between the page being loaded and the click.
    QueryRequest::whereKey($request->id)->update(['status' => QueryRequestStatus::Completed, 'backend_pid' => null]);

    expect(fn () => app(ApprovalService::class)->stop($request, $owner))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(AuditLog::where('action', 'execution.cancelled')->exists())->toBeFalse();

    // Same for a stale copy whose session id is no longer the recorded one.
    [, $owner2, , , , $other] = stopSetup();
    QueryRequest::whereKey($other->id)->update(['backend_pid' => 9999]);

    expect(fn () => app(ApprovalService::class)->stop($other, $owner2))->toThrow(AuthorizationException::class)
        ->and($other->fresh()->status)->toBe(QueryRequestStatus::Running);
});

test('Cancel: a signal that does not arrive still ends the request as cancelled and says so', function () {
    mockCanceller(signalled: false);
    [, $owner, , , , $request] = stopSetup();

    expect(app(ApprovalService::class)->stop($request, $owner))->toBeFalse()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled);

    $log = AuditLog::where('action', 'execution.cancelled')->sole();
    expect($log->metadata['signalled'])->toBeFalse();
});

test('Cancel: pending cancel and the system cancel are untouched', function () {
    mockCanceller(times: 0);
    [, $owner, , , , $running] = stopSetup();
    $pending = QueryRequest::factory()->create([
        'team_id' => $running->team_id,
        'connection_id' => $running->connection_id,
        'user_id' => $owner->id,
    ]);

    // cancel() is still pending-only ...
    expect(fn () => app(ApprovalService::class)->cancel($running, $owner))->toThrow(AuthorizationException::class);

    // ... and the system cancel keeps its nullable actor.
    expect(app(ApprovalService::class)->cancelBySystem($pending, 'owner deleted'))->toBeTrue();

    $log = AuditLog::where('query_request_id', $pending->id)->where('action', 'request.cancelled')->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->metadata['actor'])->toBe('system');
});

test('Cancel: the page offers Stop only to those who may use it, and stopping from it works', function () {
    mockCanceller();
    [, $owner, , $colleague, $auditor, $request] = stopSetup();

    Livewire::actingAs($auditor)->test(Show::class, ['queryRequest' => $request])
        ->assertViewHas('canStop', false)
        ->assertDontSee('Stop query');

    Livewire::actingAs($owner)->test(Show::class, ['queryRequest' => $request])
        ->assertViewHas('canStop', true)
        ->assertSee('Stop query')
        ->call('stop')
        ->assertSet('notice', null)
        ->assertViewHas('canStop', false)
        ->assertDontSee('Stop query');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled);
});

test('Cancel: the page hides Stop while no session id is known and warns when the signal failed', function () {
    mockCanceller(signalled: false);
    [, $owner, , , , $request] = stopSetup(['backend_pid' => null]);

    Livewire::actingAs($owner)->test(Show::class, ['queryRequest' => $request])
        ->assertViewHas('canStop', false)
        ->assertDontSee('Stop query');

    QueryRequest::whereKey($request->id)->update(['backend_pid' => 4242]);

    Livewire::actingAs($owner)->test(Show::class, ['queryRequest' => $request->fresh()])
        ->assertViewHas('canStop', true)
        ->call('stop')
        ->assertSee('could not be reached');
});

/*
 * The executor's side: it records the session id while it runs and gives way
 * when the request was stopped underneath it.
 */

function stopExecutorSetup(string $sql, array $overrides = []): QueryRequest
{
    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_cancel_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, active INTEGER DEFAULT 1)');
    $pdo->exec("INSERT INTO customers (name) VALUES ('Ada'), ('Grace'), ('Alan')");
    unset($pdo);

    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->users()->attach($owner, ['role' => 'developer']);
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id]);

    return QueryRequest::factory()->create($overrides + [
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $owner->id,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
        'status' => QueryRequestStatus::Queued,
    ]);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_cancel_*') ?: [] as $file) {
        @unlink($file);
    }
});

/** What backend_pid / backend_session the request row holds when the target query is sent. */
function watchBackendPid(QueryRequest $request): ArrayObject
{
    $seen = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($request, $seen) {
        if (str_starts_with($query->connectionName, 'queryproxy_target_')) {
            $row = QueryRequest::whereKey($request->id)->first(['backend_pid', 'backend_session']);
            $seen[] = [$row->backend_pid, $row->backend_session];
        }
    });

    return $seen;
}

test('Cancel: a run records its session id while it executes and clears it when it ends', function (string $sql, string $type) {
    test()->mock(BackendCanceller::class, function ($mock) {
        $mock->shouldReceive('identify')->once()->andReturn(['pid' => 4242, 'token' => 'stamp-1']);
    });
    $request = stopExecutorSetup($sql, ['type' => $type]);
    $seen = watchBackendPid($request);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Completed)
        ->and($request->backend_pid)->toBeNull()
        ->and($request->backend_session)->toBeNull()
        ->and($seen->getArrayCopy())->toContain([4242, 'stamp-1']);
})->with([
    'read' => ['SELECT id FROM customers', 'read'],
    'write' => ['UPDATE customers SET active = 0 WHERE id = 1', 'write'],
]);

test('Cancel: a run whose session id cannot be read still completes, just not stoppable', function () {
    test()->mock(BackendCanceller::class, function ($mock) {
        $mock->shouldReceive('identify')->once()->andReturn(null);
    });
    $request = stopExecutorSetup('SELECT id FROM customers');
    $seen = watchBackendPid($request);

    app(QueryExecutor::class)->execute($request);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(array_filter($seen->getArrayCopy(), fn (array $row) => $row[0] !== null))->toBe([]);
});

test('Cancel: the error a stop causes is not recorded as a failure', function () {
    Notification::fake();
    $request = stopExecutorSetup('SELECT id FROM missing_table');
    $owner = $request->requester;

    test()->mock(BackendCanceller::class, function ($mock) use ($request, $owner) {
        $mock->shouldReceive('identify')->once()->andReturnUsing(function () use ($request, $owner) {
            // The user clicks Stop right after the session id is known; the
            // statement then dies with the database's cancel error.
            QueryRequest::whereKey($request->id)->update(['backend_pid' => 4242]);
            app(ApprovalService::class)->stop(QueryRequest::find($request->id), $owner);

            return ['pid' => 4242, 'token' => null];
        });
        $mock->shouldReceive('cancel')->once()->andReturn(true);
    });

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($request->error_message)->toBeNull()
        ->and($request->backend_pid)->toBeNull()
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'execution.cancelled')->count())->toBe(1)
        ->and(AuditLog::where('query_request_id', $request->id)->whereIn('action', ['request.execution_failed', 'execution.late_completion'])->exists())->toBeFalse();

    Notification::assertNothingSent();
});

test('Cancel: a statement that finished despite the stop is logged as a late completion and its result dropped', function () {
    Notification::fake();
    $request = stopExecutorSetup('SELECT id FROM customers');
    $owner = $request->requester;

    test()->mock(BackendCanceller::class, function ($mock) use ($request, $owner) {
        $mock->shouldReceive('identify')->once()->andReturnUsing(function () use ($request, $owner) {
            QueryRequest::whereKey($request->id)->update(['backend_pid' => 4242]);
            app(ApprovalService::class)->stop(QueryRequest::find($request->id), $owner);

            return ['pid' => 4242, 'token' => null];
        });
        $mock->shouldReceive('cancel')->once()->andReturn(true);
    });

    app(QueryExecutor::class)->execute($request);

    $request->refresh();
    $late = AuditLog::where('query_request_id', $request->id)->where('action', 'execution.late_completion')->sole();

    expect($request->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($request->result_path)->toBeNull()
        ->and($late->metadata['status'])->toBe('cancelled')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Notification::assertNotSentTo($owner, QueryRequestFinished::class);
});

test('Cancel: the job safety net cancels the statement on the target and clears the session', function () {
    [, , , , , $request] = stopSetup(['backend_session' => 'stamp-9']);

    test()->mock(BackendCanceller::class, function ($mock) use ($request) {
        $mock->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (Connection $connection, int $pid, ?string $token, bool $asReader) => $connection->is($request->connection) && $pid === 4242 && $token === 'stamp-9')
            ->andReturn(true);
    });

    (new ExecuteQueryRequest($request))->failed(new RuntimeException('worker died'));

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->backend_pid)->toBeNull()
        ->and($request->backend_session)->toBeNull()
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_failed')->exists())->toBeTrue();
});

test('Cancel: the job safety net signals nothing when no session was recorded or the run had already ended', function (QueryRequestStatus $status, ?int $pid) {
    test()->mock(BackendCanceller::class, fn ($mock) => $mock->shouldNotReceive('cancel'));
    [, , , , , $request] = stopSetup(['status' => $status, 'backend_pid' => $pid]);

    (new ExecuteQueryRequest($request))->failed(new RuntimeException('worker died'));
})->with([
    'running, session not recorded' => [QueryRequestStatus::Running, null],
    'already completed' => [QueryRequestStatus::Completed, null],
    'already cancelled by a user' => [QueryRequestStatus::Cancelled, null],
]);

test('Cancel: the job safety net still fails and audits the request when the signal blows up', function () {
    [, , , , , $request] = stopSetup();

    test()->mock(BackendCanceller::class, fn ($mock) => $mock->shouldReceive('cancel')->once()->andThrow(new RuntimeException('boom')));

    (new ExecuteQueryRequest($request))->failed(new RuntimeException('worker died'));

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', 'request.execution_failed')->exists())->toBeTrue();
});

test('Cancel: stopping a transactional write between statements rolls the whole transaction back', function () {
    Notification::fake();
    $request = stopExecutorSetup(
        "UPDATE customers SET active = 0 WHERE id = 1; UPDATE customers SET name = 'changed' WHERE id = 2",
        ['type' => 'write'],
    );
    $owner = $request->requester;
    $dbFile = $request->connection->database;

    test()->mock(BackendCanceller::class, function ($mock) {
        $mock->shouldReceive('identify')->once()->andReturn(['pid' => 4242, 'token' => null]);
        // The signal reaches an idle session (the gap between two statements): it cancels nothing.
        $mock->shouldReceive('cancel')->once()->andReturn(true);
    });

    $stopped = false;

    DB::listen(function (QueryExecuted $query) use (&$stopped, $request, $owner) {
        if (! $stopped && str_starts_with($query->connectionName, 'queryproxy_target_') && str_starts_with($query->sql, 'UPDATE')) {
            $stopped = true;
            app(ApprovalService::class)->stop(QueryRequest::find($request->id), $owner);
        }
    });

    app(QueryExecutor::class)->execute($request);

    $pdo = new PDO('sqlite:'.$dbFile);
    $rows = $pdo->query('SELECT id, active, name FROM customers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    expect($stopped)->toBeTrue()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled)
        // Neither statement is left behind.
        ->and($rows[0]['active'])->toBe(1)
        ->and($rows[1]['name'])->toBe('Grace')
        ->and(AuditLog::where('query_request_id', $request->id)->whereIn('action', ['request.execution_completed', 'request.execution_failed', 'execution.late_completion'])->exists())->toBeFalse();
});

class CancelLostPdo extends PDO
{
    public static int $attempts = 0;

    #[ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'UPDATE')) {
            self::$attempts++;

            throw new PDOException('SQLSTATE[08S01]: Communication link failure: connection reset by peer');
        }

        return parent::prepare($query, $options);
    }
}

test('Cancel: a write that loses its connection is never re-run on a new session', function () {
    CancelLostPdo::$attempts = 0;
    $request = stopExecutorSetup('UPDATE customers SET active = 0 WHERE id = 1', ['type' => 'write']);
    $dbFile = $request->connection->database;

    DB::extend('cancel_lost', fn (array $config, string $name) => new SQLiteConnection(new CancelLostPdo('sqlite:'.$config['database']), $config['database'], '', $config + ['name' => $name]));

    test()->partialMock(DynamicConnectionFactory::class, function ($mock) use ($dbFile) {
        $mock->shouldReceive('configure')->andReturnUsing(function ($connection) use ($dbFile) {
            $name = 'queryproxy_target_'.$connection->id;
            config(["database.connections.{$name}" => ['driver' => 'cancel_lost', 'database' => $dbFile, 'prefix' => '']]);

            return $name;
        });
    });

    app(QueryExecutor::class)->execute($request);

    expect(CancelLostPdo::$attempts)->toBe(1)
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Failed);

    $pdo = new PDO('sqlite:'.$dbFile);
    expect((int) $pdo->query('SELECT active FROM customers WHERE id = 1')->fetchColumn())->toBe(1);
});

test('Cancel: a stale page whose session stamp no longer matches cannot claim the run', function () {
    mockCanceller(times: 0);
    [, $owner, , , , $request] = stopSetup(['backend_session' => 'stamp-old']);

    QueryRequest::whereKey($request->id)->update(['backend_session' => 'stamp-new']);

    expect(fn () => app(ApprovalService::class)->stop($request, $owner))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Running);
});

test('Cancel: the signal carries the recorded stamp and whether the run used the read identity', function () {
    [, $owner, , , , $request] = stopSetup(['backend_session' => 'stamp-1', 'type' => 'write']);

    test()->mock(BackendCanceller::class, function ($mock) {
        $mock->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (Connection $connection, int $pid, ?string $token, bool $asReader) => $pid === 4242 && $token === 'stamp-1' && $asReader === false)
            ->andReturn(true);
    });

    app(ApprovalService::class)->stop($request, $owner);

    expect($request->fresh()->backend_session)->toBeNull();
});

test('Cancel: the requester is told in-app when somebody else stops the query, not when they stop it themselves', function (string $stopper, bool $notified) {
    Notification::fake();
    mockCanceller();
    [, $owner, $dba, , , $request] = stopSetup();
    $actor = match ($stopper) {
        'requester' => $owner,
        'dba' => $dba,
        'admin' => User::factory()->create(['is_admin' => true]),
    };

    app(ApprovalService::class)->stop($request, $actor);

    if ($notified) {
        Notification::assertSentTo($owner, QueryRequestStopped::class, fn (QueryRequestStopped $n) => $n->request->is($request)
            && $n->stoppedBy->is($actor)
            && str_contains($n->toArray($owner)['message'], $actor->name)
            && $n->via($owner) === ['database']);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    'requester stops own' => ['requester', false],
    'dba stops' => ['dba', true],
    'admin stops' => ['admin', true],
]);

test('Cancel: a stop that did not claim the run notifies nobody', function () {
    Notification::fake();
    mockCanceller(times: 0);
    [, $owner, $dba, , , $request] = stopSetup();
    QueryRequest::whereKey($request->id)->update(['status' => QueryRequestStatus::Completed, 'backend_pid' => null]);

    expect(fn () => app(ApprovalService::class)->stop($request, $dba))->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

test('Cancel: the notification is stored for the requester', function () {
    mockCanceller();
    [, $owner, $dba, , , $request] = stopSetup();

    app(ApprovalService::class)->stop($request, $dba);

    $stored = $owner->notifications()->sole();

    expect($stored->type)->toBe(QueryRequestStopped::class)
        ->and($stored->data['query_request_id'])->toBe($request->id)
        ->and($stored->data['kind'])->toBe('stopped');
});

test('Cancel: clicking Stop on a query that just ended shows a message, not a 403', function () {
    mockCanceller(times: 0);
    [, $owner, , , , $request] = stopSetup();

    $component = Livewire::actingAs($owner)->test(Show::class, ['queryRequest' => $request]);

    // The run completes between the page being rendered and the click.
    QueryRequest::whereKey($request->id)->update(['status' => QueryRequestStatus::Completed, 'backend_pid' => null]);

    $component->call('stop')
        ->assertOk()
        ->assertSet('notice', 'This request is no longer running.');
});

test('Cancel: a user who may not stop a running query still gets a 403 from the page action', function () {
    mockCanceller(times: 0);
    [, , , , $auditor, $request] = stopSetup();

    Livewire::actingAs($auditor)->test(Show::class, ['queryRequest' => $request])
        ->call('stop')
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Running);
});

/*
 * BackendCanceller: the statements sent to the second connection.
 */

class CancelControlConnection extends SQLiteConnection
{
    /** @var list<string> */
    public static array $sent = [];

    public static bool $pgsqlResult = true;

    /** What the SQL Server session lookup (selectOne) finds: null when no session matches. */
    public static ?object $sessionLookup = null;

    public static bool $fail = false;

    public function unprepared($query)
    {
        self::$sent[] = $query;

        if (self::$fail) {
            throw new RuntimeException('control connection refused');
        }

        return true;
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        self::$sent[] = $query;

        return self::$sessionLookup;
    }

    public function scalar($query, $bindings = [], $useReadPdo = true)
    {
        self::$sent[] = $query.' ['.implode(',', $bindings).']';

        if (self::$fail) {
            throw new RuntimeException('control connection refused');
        }

        return self::$pgsqlResult;
    }
}

/**
 * @param  list<bool>  $identities  filled with the readIdentity flag of every control connection opened
 */
function cancelFakeControl(array &$identities): void
{
    CancelControlConnection::$sent = [];
    CancelControlConnection::$pgsqlResult = true;
    CancelControlConnection::$fail = false;
    CancelControlConnection::$sessionLookup = (object) ['matched' => 1];

    DB::extend('cancel_control', fn (array $config, string $name) => new CancelControlConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]));

    test()->partialMock(DynamicConnectionFactory::class, function ($mock) use (&$identities) {
        $mock->shouldReceive('configureControl')->andReturnUsing(function (Connection $connection, bool $readIdentity = false) use (&$identities) {
            $identities[] = $readIdentity;
            $name = 'queryproxy_target_'.$connection->id.'_control_'.count($identities);
            config(["database.connections.{$name}" => ['driver' => 'cancel_control', 'database' => ':memory:', 'prefix' => '']]);

            return $name;
        });
    });
}

test('Cancel: the signal is the right statement for each driver, built from an integer id', function (DbDriver $driver, ?string $token, array $expected) {
    $identities = [];
    cancelFakeControl($identities);
    $connection = Connection::factory()->create(['driver' => $driver]);

    expect(app(BackendCanceller::class)->cancel($connection, 4242, $token))->toBeTrue()
        ->and(CancelControlConnection::$sent)->toBe($expected)
        ->and($identities)->toBe([false]);
})->with([
    'pgsql without a stamp' => [DbDriver::Pgsql, null, ['SELECT pg_cancel_backend(?) [4242]']],
    'pgsql with the session stamp' => [DbDriver::Pgsql, '2026-10-24 10:00:00.123456+00', [
        'SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE pid = ? AND backend_start = CAST(? AS timestamptz) [4242,2026-10-24 10:00:00.123456+00]',
    ]],
    'mysql' => [DbDriver::Mysql, null, ['KILL QUERY 4242']],
    'mariadb' => [DbDriver::Mariadb, null, ['KILL QUERY 4242']],
    'sqlsrv with the session stamp' => [DbDriver::Sqlsrv, '2026-10-24 10:00:00.123', [
        "SELECT 1 FROM sys.dm_exec_sessions WHERE session_id = 4242 AND login_time = CONVERT(datetime, '2026-10-24 10:00:00.123', 121)",
        "IF EXISTS (SELECT 1 FROM sys.dm_exec_sessions WHERE session_id = 4242 AND login_time = CONVERT(datetime, '2026-10-24 10:00:00.123', 121)) KILL 4242",
    ]],
]);

test('Cancel: SQL Server is only killed when the session still carries the recorded stamp', function (?string $token, bool $sessionFound) {
    $identities = [];
    cancelFakeControl($identities);
    CancelControlConnection::$sessionLookup = $sessionFound ? (object) ['matched' => 1] : null;
    $connection = Connection::factory()->create(['driver' => DbDriver::Sqlsrv]);

    expect(app(BackendCanceller::class)->cancel($connection, 4242, $token))->toBeFalse();

    // Never a KILL without a trusted stamp and a session that matches it.
    expect(implode("\n", CancelControlConnection::$sent))->not->toContain('KILL');
})->with([
    'no stamp recorded' => [null, true],
    'stamp that is not a timestamp' => ["2026-10-24'; KILL 1; --", true],
    'stamp with more precision than datetime has' => ['2026-10-24 10:00:00.1234567', true],
    'the session id now belongs to a different login' => ['2026-10-24 10:00:00.123', false],
]);

test('Cancel: SQLite and an invalid id are never signalled', function () {
    $identities = [];
    cancelFakeControl($identities);

    expect(app(BackendCanceller::class)->cancel(Connection::factory()->sqlite()->create(), 4242))->toBeFalse()
        ->and(app(BackendCanceller::class)->cancel(Connection::factory()->create(['driver' => DbDriver::Mysql]), 0))->toBeFalse()
        ->and($identities)->toBe([])
        ->and(CancelControlConnection::$sent)->toBe([]);
});

test('Cancel: a read run tries the read identity first, every other run only the main one', function (bool $asReader, array $expected) {
    $identities = [];
    cancelFakeControl($identities);
    CancelControlConnection::$pgsqlResult = false;

    $connection = Connection::factory()->create([
        'driver' => DbDriver::Pgsql,
        'read_username' => 'reader',
        'read_password' => 'secret',
    ]);

    // pg_cancel_backend() answers false for every identity: nothing delivered.
    expect(app(BackendCanceller::class)->cancel($connection, 7, null, $asReader))->toBeFalse()
        ->and($identities)->toBe($expected);
})->with([
    'a read' => [true, [true, false]],
    'a write' => [false, [false]],
]);

test('Cancel: runsAsReader is a single-statement read on a connection with a read identity', function () {
    $connection = Connection::factory()->create(['driver' => DbDriver::Pgsql, 'read_username' => 'reader', 'read_password' => 'x']);
    $plain = Connection::factory()->create(['driver' => DbDriver::Pgsql]);

    expect(QueryRequest::factory()->create(['connection_id' => $connection->id, 'team_id' => $connection->team_id, 'type' => 'read'])->runsAsReader())->toBeTrue()
        ->and(QueryRequest::factory()->create(['connection_id' => $connection->id, 'team_id' => $connection->team_id, 'type' => 'write'])->runsAsReader())->toBeFalse()
        // A multi-statement read runs on the main identity.
        ->and(QueryRequest::factory()->create(['connection_id' => $connection->id, 'team_id' => $connection->team_id, 'type' => 'read', 'statement_count' => 2])->runsAsReader())->toBeFalse()
        ->and(QueryRequest::factory()->create(['connection_id' => $plain->id, 'team_id' => $plain->team_id, 'type' => 'read'])->runsAsReader())->toBeFalse();
});

test('Cancel: a control connection that cannot be used yields false instead of an exception', function () {
    $identities = [];
    cancelFakeControl($identities);
    CancelControlConnection::$fail = true;

    $connection = Connection::factory()->create(['driver' => DbDriver::Mysql]);

    expect(app(BackendCanceller::class)->cancel($connection, 7))->toBeFalse();
});

test('Cancel: the session id is read through the raw PDO and a failure is just null', function () {
    $canceller = app(BackendCanceller::class);

    $db = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
    $db->getPdo()->sqliteCreateFunction('CONNECTION_ID', fn () => 55);

    // MySQL ids only grow, so there is no stamp to record.
    expect($canceller->identify($db, DbDriver::Mysql))->toBe(['pid' => 55, 'token' => null])
        ->and($canceller->identify($db, DbDriver::Sqlite))->toBeNull()
        // pg_stat_activity / sys.dm_exec_sessions do not exist on SQLite.
        ->and($canceller->identify($db, DbDriver::Pgsql))->toBeNull()
        ->and($canceller->identify($db, DbDriver::Sqlsrv))->toBeNull();
});

test('Cancel: the control connection is configured like the target and removed by purge', function () {
    $factory = app(DynamicConnectionFactory::class);
    $connection = Connection::factory()->create(['driver' => DbDriver::Pgsql, 'read_username' => 'reader', 'read_password' => 'x']);

    $main = $factory->configureControl($connection);
    $read = $factory->configureControl($connection, readIdentity: true);

    expect($main)->toBe('queryproxy_target_'.$connection->id.'_control')
        ->and($read)->toBe('queryproxy_target_'.$connection->id.'_read_control')
        ->and(config("database.connections.{$main}.username"))->toBe($connection->username)
        ->and(config("database.connections.{$read}.username"))->toBe('reader')
        ->and(config("database.connections.{$main}.options"))->toHaveKey(PDO::ATTR_TIMEOUT);

    // Driver-default TLS: required TLS needs pdo_sqlsrv, which the suite does not have.
    $sqlsrv = Connection::factory()->create(['driver' => DbDriver::Sqlsrv, 'options' => ['tls' => ['mode' => null, 'flag' => Connection::TLS_DRIVER_DEFAULT]]]);

    expect(config('database.connections.'.$factory->configureControl($sqlsrv).'.login_timeout'))->toBe(5);

    $factory->purge($connection);

    expect(config("database.connections.{$main}"))->toBeNull()
        ->and(config("database.connections.{$read}"))->toBeNull();
});
