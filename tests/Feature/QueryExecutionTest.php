<?php

use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Notifications\QueryRequestFinished;
use App\Services\Execution\QueryExecutor;
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
        ->and(json_decode($lines[0], true))->toBe([1, 'Ada Lovelace', 'ada@example.com']);
});

test('masking rules are applied before results hit storage', function () {
    [$request, , $team] = executionSetup('SELECT name, email FROM customers ORDER BY id LIMIT 10');

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
