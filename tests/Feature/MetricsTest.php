<?php

use App\Enums\DbDriver;
use App\Enums\GuardRule;
use App\Enums\QueryRequestStatus;
use App\Enums\StatementType;
use App\Jobs\ExecuteQueryRequest;
use App\Jobs\Middleware\LimitConnectionConcurrency;
use App\Livewire\Studio\QueryStudio;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Execution\BackendCanceller;
use App\Services\Execution\QueryExecutor;
use App\Services\Metrics\Metric;
use App\Services\Metrics\MetricsExporter;
use App\Services\Metrics\MetricsRecorder;
use App\Services\Sql\SqlInspector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const METRICS_TEST_TOKEN = 'metrics-token-0123456789abcdef';

/** The exposition text as the endpoint renders it. */
function metricsOutput(): string
{
    return app(MetricsExporter::class)->render();
}

/** The value of one sample line, e.g. `queryproxy_requests_total{kind="read",status="pending"}`; null when absent. */
function metricValue(string $series, ?string $output = null): ?float
{
    $output ??= metricsOutput();

    foreach (explode("\n", $output) as $line) {
        if (str_starts_with($line, $series.' ')) {
            return (float) substr($line, strlen($series) + 1);
        }
    }

    return null;
}

/** A pending request on a SQLite-free connection, with a DBA of its team who can decide it. */
function metricsApprovalSetup(array $overrides = []): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    $request = QueryRequest::factory()->create($overrides + [
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'type' => StatementType::Read,
    ]);

    session(['current_team_id' => $team->id]);

    return [$dba, $developer, $request];
}

afterEach(function () {
    Storage::fake('local');
});

// --- access ----------------------------------------------------------------

test('Metrics: the endpoint is off when neither a token nor an allowlist is configured', function () {
    $this->get('/metrics')->assertNotFound();
    $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TEST_TOKEN])->assertNotFound();
});

test('Metrics: a missing or wrong token gets 401', function () {
    config(['queryproxy.metrics.token' => METRICS_TEST_TOKEN]);

    $this->get('/metrics')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="metrics"');
    $this->get('/metrics', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    $this->get('/metrics', ['Authorization' => 'Basic '.METRICS_TEST_TOKEN])->assertUnauthorized();
    $this->get('/metrics?token='.METRICS_TEST_TOKEN)->assertUnauthorized();
});

test('Metrics: the right token gets the Prometheus text format and no session', function () {
    config(['queryproxy.metrics.token' => METRICS_TEST_TOKEN]);

    $response = $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TEST_TOKEN]);

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->getContent())->toContain('# TYPE queryproxy_requests_total counter');
});

test('Metrics: an allowed address passes without a token, others get 401', function () {
    config(['queryproxy.metrics.allowed_ips' => ['192.0.2.7']]);

    $this->get('/metrics')->assertUnauthorized()->assertHeaderMissing('WWW-Authenticate');
    $this->get('/metrics', ['REMOTE_ADDR' => '192.0.2.8'])->assertUnauthorized();
    $this->get('/metrics', ['REMOTE_ADDR' => '192.0.2.7'])->assertOk();
});

test('Metrics: the allowlist takes CIDR ranges and IPv6', function () {
    config(['queryproxy.metrics.allowed_ips' => ['10.1.0.0/16', '2001:db8::/32']]);

    $this->get('/metrics', ['REMOTE_ADDR' => '10.1.200.3'])->assertOk();
    $this->get('/metrics', ['REMOTE_ADDR' => '2001:db8::5'])->assertOk();
    $this->get('/metrics', ['REMOTE_ADDR' => '10.2.0.1'])->assertUnauthorized();
});

test('Metrics: with both configured, either one is enough', function () {
    config(['queryproxy.metrics.token' => METRICS_TEST_TOKEN, 'queryproxy.metrics.allowed_ips' => ['192.0.2.7']]);

    $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TEST_TOKEN])->assertOk();
    $this->get('/metrics', ['REMOTE_ADDR' => '192.0.2.7'])->assertOk();
    $this->get('/metrics', ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

test('Metrics: repeated guesses are throttled', function () {
    config(['queryproxy.metrics.token' => METRICS_TEST_TOKEN]);

    foreach (range(1, 120) as $ignored) {
        $this->get('/metrics', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    $this->get('/metrics', ['Authorization' => 'Bearer wrong'])->assertStatus(429);
});

// --- exposition format -----------------------------------------------------

test('Metrics: the output is well-formed exposition text', function () {
    $recorder = app(MetricsRecorder::class);
    [, , $request] = metricsApprovalSetup(['reviewed_at' => now()]);

    $recorder->requestEntered(QueryRequestStatus::Pending, StatementType::Read);
    $recorder->approvalDecided($request);
    $recorder->executionFinished($request, 120);
    $recorder->guardRejected(['Statement 1: DELETE without a WHERE clause is not allowed.']);
    $recorder->maskedValues(['column' => 4]);
    $recorder->slotDeferred();

    $output = metricsOutput();

    expect($output)->toEndWith("\n");

    $declared = [];

    foreach (explode("\n", rtrim($output, "\n")) as $line) {
        if (str_starts_with($line, '# HELP ')) {
            expect($line)->toMatch('/^# HELP queryproxy_[a-z_]+ \S.*$/');

            continue;
        }

        if (str_starts_with($line, '# TYPE ')) {
            expect($line)->toMatch('/^# TYPE queryproxy_[a-z_]+ (counter|gauge|histogram)$/');
            $declared[] = explode(' ', $line)[2];

            continue;
        }

        expect($line)->toMatch('/^queryproxy_[a-z_]+(\{([a-z_]+="[^"\\\\\n]*",?)+\})? -?\d+(\.\d+)?$/');

        // Every sample belongs to a family that was declared before it.
        $name = preg_replace('/(_bucket|_sum|_count)?(\{.*)?( .*)$/', '', $line);
        expect($declared)->toContain($name);
    }

    foreach (['queryproxy_requests_total', 'queryproxy_requests_pending', 'queryproxy_approval_latency_seconds', 'queryproxy_execution_duration_seconds', 'queryproxy_guard_rejections_total', 'queryproxy_masked_values_total', 'queryproxy_queue_depth', 'queryproxy_failed_jobs'] as $family) {
        expect($output)->toContain("# TYPE {$family} ");
    }
});

test('Metrics: counters are exposed at 0 before their first event', function () {
    $output = metricsOutput();

    expect(metricValue('queryproxy_requests_total{kind="read",status="pending"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_requests_total{kind="write",status="cancelled"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_guard_rejections_total{rule="where_clause"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_slot_deferred_requests_total', $output))->toBe(0.0)
        // Queued is an internal hop, not a status that is counted.
        ->and($output)->not->toContain('status="queued"');
});

test('Metrics: a histogram is cumulative and carries _sum and _count', function () {
    [, , $request] = metricsApprovalSetup();
    $recorder = app(MetricsRecorder::class);

    // 7 ms -> le 0.01, 400 ms -> le 0.5, 1.2 s -> le 2.5, 900 s -> +Inf
    foreach ([7, 400, 1200, 900_000] as $milliseconds) {
        $recorder->executionFinished($request, $milliseconds);
    }

    $series = 'queryproxy_execution_duration_seconds';
    $driver = $request->connection->driver->value;
    $output = metricsOutput();

    expect(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"0.01\"}", $output))->toBe(1.0)
        ->and(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"0.05\"}", $output))->toBe(1.0)
        ->and(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"0.5\"}", $output))->toBe(2.0)
        ->and(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"2.5\"}", $output))->toBe(3.0)
        ->and(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"300\"}", $output))->toBe(3.0)
        ->and(metricValue("{$series}_bucket{driver=\"{$driver}\",le=\"+Inf\"}", $output))->toBe(4.0)
        ->and(metricValue("{$series}_count{driver=\"{$driver}\"}", $output))->toBe(4.0)
        ->and(metricValue("{$series}_sum{driver=\"{$driver}\"}", $output))->toBe(901.607);
});

test('Metrics: every write goes to the one shared row', function () {
    $recorder = app(MetricsRecorder::class);

    foreach (range(1, 5) as $ignored) {
        $recorder->requestEntered(QueryRequestStatus::Failed, StatementType::Write);
    }

    expect(DB::table('metric_samples')->where('name', 'queryproxy_requests_total')->count())->toBe(1)
        ->and(metricValue('queryproxy_requests_total{kind="write",status="failed"}'))->toBe(5.0);
});

test('Metrics: inside a transaction the count waits for the commit and dies with a rollback', function () {
    $recorder = app(MetricsRecorder::class);

    DB::transaction(function () use ($recorder) {
        $recorder->requestEntered(QueryRequestStatus::Cancelled, StatementType::Read);

        // Not written yet: nothing a rollback would have to undo.
        expect(DB::table('metric_samples')->count())->toBe(0);
    });

    expect(metricValue('queryproxy_requests_total{kind="read",status="cancelled"}'))->toBe(1.0);

    try {
        DB::transaction(function () use ($recorder) {
            $recorder->requestEntered(QueryRequestStatus::Cancelled, StatementType::Read);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(metricValue('queryproxy_requests_total{kind="read",status="cancelled"}'))->toBe(1.0);
});

test('Metrics: a metric that cannot be written does not disturb the transaction around it', function () {
    Schema::drop('metric_samples');

    $recorder = app(MetricsRecorder::class);

    $result = DB::transaction(function () use ($recorder) {
        $recorder->requestEntered(QueryRequestStatus::Cancelled, StatementType::Read);
        $recorder->guardRejected(['DELETE without a WHERE clause is not allowed.']);

        return DB::table('users')->count();
    });

    expect($result)->toBe(0);
});

test('Metrics: a failing store is logged, never thrown', function () {
    Schema::drop('metric_samples');

    $recorder = app(MetricsRecorder::class);
    $recorder->requestEntered(QueryRequestStatus::Pending, StatementType::Read);
    $recorder->slotDeferred();
    $recorder->guardRejected(['Statement 1: DELETE without a WHERE clause is not allowed.']);

    expect(true)->toBeTrue();
});

test('Metrics: no label can carry free text', function () {
    $recorder = app(MetricsRecorder::class);

    $recorder->guardRejected(["Statement 1: DELETE FROM secret_table_x is not allowed (user alice@example.com) \"quoted\"\nnewline"]);
    $recorder->maskedValues(['team-acme-rule' => 9, 'column' => 1]);

    $output = metricsOutput();

    expect($output)->not->toContain('secret_table_x')
        ->and($output)->not->toContain('alice')
        ->and($output)->not->toContain('acme')
        ->and(metricValue('queryproxy_masked_values_total{rule="column"}', $output))->toBe(1.0);
});

// --- gauges ----------------------------------------------------------------

test('Metrics: the gauges are computed at scrape time', function () {
    expect(metricValue('queryproxy_requests_pending'))->toBe(0.0)
        ->and(metricValue('queryproxy_failed_jobs'))->toBe(0.0)
        ->and(metricValue('queryproxy_queue_depth{queue="queries"}'))->toBe(0.0);

    metricsApprovalSetup();
    metricsApprovalSetup();
    metricsApprovalSetup(['status' => QueryRequestStatus::Completed]);

    DB::table('failed_jobs')->insert([
        'uuid' => 'a-1', 'connection' => 'database', 'queue' => 'queries',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    expect(metricValue('queryproxy_requests_pending'))->toBe(2.0)
        ->and(metricValue('queryproxy_failed_jobs'))->toBe(1.0);
});

test('Metrics: queue depth reads the configured queue backend', function () {
    config(['queue.default' => 'database', 'queryproxy.siem.queue' => 'siem']);

    DB::table('jobs')->insert([
        ['queue' => 'queries', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ['queue' => 'queries', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
        ['queue' => 'siem', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()],
    ]);

    $output = metricsOutput();

    expect(metricValue('queryproxy_queue_depth{queue="queries"}', $output))->toBe(2.0)
        ->and(metricValue('queryproxy_queue_depth{queue="siem"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_queue_depth{queue="default"}', $output))->toBe(0.0);
});

test('Metrics: a gauge that cannot be computed is left out, not reported as 0', function () {
    Schema::drop('failed_jobs');

    expect(metricsOutput())->not->toContain('queryproxy_failed_jobs');
});

// --- event points ----------------------------------------------------------

test('Metrics: submitting a request counts it as pending, by kind', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT * FROM customers')
        ->call('submit');

    Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'UPDATE customers SET active = 0 WHERE id = 1')
        ->call('submit');

    $output = metricsOutput();

    expect(metricValue('queryproxy_requests_total{kind="read",status="pending"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_requests_total{kind="write",status="pending"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_requests_pending', $output))->toBe(2.0)
        ->and($output)->not->toContain('customers');
});

test('Metrics: a statement the guard refuses is counted by rule family, without its text', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'DELETE FROM customers')
        ->call('submit');

    Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'GRANT ALL ON customers TO mallory')
        ->call('submit');

    $output = metricsOutput();

    expect(QueryRequest::count())->toBe(0)
        ->and(metricValue('queryproxy_guard_rejections_total{rule="where_clause"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_guard_rejections_total{rule="privilege_management"}', $output))->toBe(1.0)
        ->and($output)->not->toContain('mallory');
});

test('Metrics: the Validate button is a preview, not a rejection', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    $studio = Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'DELETE FROM customers');

    foreach (range(1, 3) as $ignored) {
        $studio->call('validateSql');
    }

    expect($studio->get('violations'))->not->toBe([])
        ->and(DB::table('metric_samples')->where('name', 'queryproxy_guard_rejections_total')->count())->toBe(0);

    $studio->call('submit');

    expect(metricValue('queryproxy_guard_rejections_total{rule="where_clause"}'))->toBe(1.0);
});

test('Metrics: a passing statement is not a guard rejection', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1')
        ->call('validateSql')
        ->call('submit');

    expect(DB::table('metric_samples')->where('name', 'queryproxy_guard_rejections_total')->count())->toBe(0);
});

test('Metrics: guard rules are classified from the real inspector messages', function (string $sql, GuardRule $rule) {
    $violations = app(SqlInspector::class)->inspect($sql, DbDriver::Pgsql)->violations;

    expect($violations)->not->toBe([])
        ->and(GuardRule::fromViolations($violations))->toContain($rule);
})->with([
    'delete without where' => ['DELETE FROM customers', GuardRule::WhereClause],
    'update without where' => ['UPDATE customers SET active = 0', GuardRule::WhereClause],
    'grant' => ['GRANT ALL ON customers TO bob', GuardRule::PrivilegeManagement],
    'create user' => ['CREATE USER bob', GuardRule::PrivilegeManagement],
    'set role' => ['SET ROLE admin', GuardRule::PrivilegeManagement],
    'drop database' => ['DROP DATABASE prod', GuardRule::ServerControl],
    'shutdown' => ['SHUTDOWN', GuardRule::ServerControl],
    'server control function' => ['SELECT pg_terminate_backend(1)', GuardRule::ServerControl],
    'file function' => ["SELECT pg_read_file('/etc/passwd')", GuardRule::FileIo],
    'outfile' => ["SELECT * INTO OUTFILE '/tmp/x' FROM customers", GuardRule::FileIo],
    'rollback' => ['ROLLBACK', GuardRule::TransactionControl],
    'bare multi-statement' => ['DELETE FROM a WHERE id = 1; DELETE FROM b WHERE id = 1', GuardRule::TransactionControl],
    'dangerous variable' => ['SET GLOBAL general_log = 1', GuardRule::SessionSetting],
]);

test('Metrics: the SQL Server batch messages that cannot be read all land in unreadable_sql', function () {
    // Read the messages from the inspector's own source, so a new one of the
    // family that the classifier sends elsewhere fails here.
    $source = (string) file_get_contents(app_path('Services/Sql/SqlInspector.php'));

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*QueryProxy cannot tell where(?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

    expect(count($matches[1]))->toBeGreaterThan(5);

    foreach ($matches[1] as $message) {
        expect(GuardRule::classify($message))->toBe(GuardRule::UnreadableSql, $message);
    }

    expect(GuardRule::classify('Transaction control (BEGIN TRAN, COMMIT, ROLLBACK, SAVE TRAN) inside a SQL Server batch is not allowed: QueryProxy runs the request in its own transaction.'))
        ->toBe(GuardRule::TransactionControl);
});

test('Metrics: a message the classifier does not know counts as other', function () {
    expect(GuardRule::classify('Something entirely new was refused.'))->toBe(GuardRule::Other)
        ->and(GuardRule::fromViolations(['a', 'b', 'DELETE without a WHERE clause is not allowed.']))->toBe([GuardRule::Other, GuardRule::WhereClause]);
});

test('Metrics: an approval counts the status and observes how long the request waited', function () {
    Queue::fake();
    [$dba, , $request] = metricsApprovalSetup(['created_at' => now()->subSeconds(90)]);

    app(ApprovalService::class)->approve($request, $dba);

    $output = metricsOutput();

    expect(metricValue('queryproxy_requests_total{kind="read",status="approved"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_count', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_bucket{le="60"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_bucket{le="300"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_sum', $output))->toBeGreaterThanOrEqual(90.0);
});

test('Metrics: a rejection is counted and observed like an approval', function () {
    [$dba, , $request] = metricsApprovalSetup(['type' => StatementType::Write]);

    app(ApprovalService::class)->reject($request, $dba, 'No.');

    $output = metricsOutput();

    expect(metricValue('queryproxy_requests_total{kind="write",status="rejected"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_count', $output))->toBe(1.0);
});

test('Metrics: a decision that lost the race is not counted', function () {
    Queue::fake();
    [$dba, , $request] = metricsApprovalSetup();

    app(ApprovalService::class)->approve($request, $dba);

    expect(fn () => app(ApprovalService::class)->reject($request->fresh(), $dba, 'Too late.'))->toThrow(AuthorizationException::class)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="rejected"}'))->toBe(0.0)
        ->and(metricValue('queryproxy_approval_latency_seconds_count'))->toBe(1.0);
});

test('Metrics: cancelling a pending request, by its owner or the system, is counted', function () {
    [, $developer, $request] = metricsApprovalSetup();
    [, , $other] = metricsApprovalSetup();

    app(ApprovalService::class)->cancel($request, $developer);
    app(ApprovalService::class)->cancelBySystem($other, 'owner deleted');
    app(ApprovalService::class)->cancelBySystem($other->fresh(), 'again');

    expect(metricValue('queryproxy_requests_total{kind="read",status="cancelled"}'))->toBe(2.0);
});

test('Metrics: stopping a running request is counted as cancelled', function () {
    [$dba, , $request] = metricsApprovalSetup([
        'status' => QueryRequestStatus::Running,
        'executed_at' => now()->subSeconds(3),
        'backend_pid' => 4242,
    ]);

    test()->mock(BackendCanceller::class, fn ($mock) => $mock->shouldReceive('cancel')->once()->andReturn(true));

    app(ApprovalService::class)->stop($request, $dba);

    expect(metricValue('queryproxy_requests_total{kind="read",status="cancelled"}'))->toBe(1.0);
});

test('Metrics: a completed run counts running, completed, its duration and the values it masked', function () {
    Storage::fake('local');

    $dbFile = tempnam(sys_get_temp_dir(), 'qp_metrics_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $pdo->exec("INSERT INTO customers (name, email) VALUES ('Ada', 'ada@example.com'), ('Grace', 'grace@navy.mil')");
    unset($pdo);

    $team = Team::factory()->create();
    $team->maskingRules()->delete();
    MaskingRule::factory()->create(['team_id' => $team->id]);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id])->id,
        'sql_original' => 'SELECT name, email FROM customers LIMIT 10',
        'sql_prepared' => 'SELECT name, email FROM customers LIMIT 10',
        'type' => StatementType::Read,
        'status' => QueryRequestStatus::Queued,
    ]);

    app(QueryExecutor::class)->execute($request);

    @unlink($dbFile);

    $output = metricsOutput();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="running"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="completed"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="failed"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_execution_duration_seconds_count{driver="sqlite"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_masked_values_total{rule="column"}', $output))->toBe(2.0);
});

test('Metrics: a failed run counts failed and its duration, and masks nothing', function () {
    Storage::fake('local');

    // An empty target: the table below does not exist, so the run fails.
    $dbFile = tempnam(sys_get_temp_dir(), 'qp_metrics_');

    $team = Team::factory()->create();
    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id])->id,
        'sql_original' => 'SELECT * FROM missing_table',
        'sql_prepared' => 'SELECT * FROM missing_table LIMIT 10',
        'type' => StatementType::Read,
        'status' => QueryRequestStatus::Queued,
    ]);

    app(QueryExecutor::class)->execute($request);
    @unlink($dbFile);

    $output = metricsOutput();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="failed"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_requests_total{kind="read",status="completed"}', $output))->toBe(0.0)
        ->and(metricValue('queryproxy_execution_duration_seconds_count{driver="sqlite"}', $output))->toBe(1.0)
        ->and(metricValue('queryproxy_masked_values_total{rule="column"}', $output))->toBe(0.0);
});

test('Metrics: the job safety net counts a request it fails', function () {
    [, , $request] = metricsApprovalSetup(['status' => QueryRequestStatus::Queued, 'type' => StatementType::Write]);

    (new ExecuteQueryRequest($request))->failed(new RuntimeException('worker died'));

    expect(metricValue('queryproxy_requests_total{kind="write",status="failed"}'))->toBe(1.0);
});

test('Metrics: a request held back for a connection slot is counted once', function () {
    config(['queryproxy.connection_slots' => 1, 'queryproxy.connection_slot_retry_delay' => 5]);
    Queue::fake();
    Cache::flush();

    [, , $request] = metricsApprovalSetup(['status' => QueryRequestStatus::Queued]);
    Cache::lock(LimitConnectionConcurrency::lockName($request->connection_id, 1), 60)->get();

    foreach ([1, 2, 3] as $ignored) {
        $job = new ExecuteQueryRequest($request);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('delete')->once();
        $job->setJob($queueJob);

        (new LimitConnectionConcurrency)->handle($job, fn () => throw new LogicException('must not run'));
    }

    expect(metricValue('queryproxy_slot_deferred_requests_total'))->toBe(1.0);
});

test('Metrics: the metric names follow the plan', function () {
    expect(array_map(fn (Metric $metric) => $metric->value, Metric::cases()))->toBe([
        'queryproxy_requests_total',
        'queryproxy_requests_pending',
        'queryproxy_approval_latency_seconds',
        'queryproxy_execution_duration_seconds',
        'queryproxy_guard_rejections_total',
        'queryproxy_masked_values_total',
        'queryproxy_slot_deferred_requests_total',
        'queryproxy_queue_depth',
        'queryproxy_failed_jobs',
    ]);
});

// --- promtool (CI) -----------------------------------------------------------

test('Metrics: the endpoint output is dumped for promtool when asked', function () {
    $dump = getenv('QUERYPROXY_METRICS_DUMP');

    if (! is_string($dump) || $dump === '') {
        $this->markTestSkipped('Set QUERYPROXY_METRICS_DUMP=<file> to write the scrape for `promtool check metrics` (CI does).');
    }

    // Every family with a value, so the check sees real samples and not just HELP lines.
    Queue::fake();
    [$dba, , $request] = metricsApprovalSetup(['created_at' => now()->subMinute()]);
    $recorder = app(MetricsRecorder::class);
    $recorder->requestEntered(QueryRequestStatus::Pending, StatementType::Read);
    app(ApprovalService::class)->approve($request, $dba);
    $recorder->executionFinished($request, 250);
    $recorder->guardRejected(['Statement 1: DELETE without a WHERE clause is not allowed.']);
    $recorder->maskedValues(['column' => 3, 'regex' => 1]);
    $recorder->slotDeferred();

    config(['queryproxy.metrics.token' => METRICS_TEST_TOKEN]);

    $response = $this->get('/metrics', ['Authorization' => 'Bearer '.METRICS_TEST_TOKEN])->assertOk();

    file_put_contents($dump, $response->getContent());

    expect(filesize($dump))->toBeGreaterThan(0);
});
