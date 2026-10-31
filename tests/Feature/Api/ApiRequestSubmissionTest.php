<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Notifications\QueryRequestSubmitted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiWorld;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

test('a request submitted through the API is pending, audited with channel api, and notifies the approvers', function () {
    $world = ApiWorld::make();

    $response = ApiWorld::as(ApiWorld::token($world->developer))->postJson('/api/v1/requests', [
        'connection_id' => $world->connection->id,
        'sql' => 'SELECT id FROM users',
        'title' => 'Nightly check',
        'reason' => 'Needed for the report',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.title', 'Nightly check')
        ->assertJsonPath('data.note', 'Needed for the report')
        ->assertJsonPath('data.requested_by', $world->developer->id)
        ->assertJsonPath('data.sql_original', 'SELECT id FROM users');

    $queryRequest = QueryRequest::sole();

    expect($queryRequest->status)->toBe(QueryRequestStatus::Pending)
        ->and($queryRequest->user_id)->toBe($world->developer->id)
        ->and($queryRequest->team_id)->toBe($world->team->id)
        ->and($response->headers->get('Location'))->toEndWith("/api/v1/requests/{$queryRequest->id}");

    $audit = AuditLog::where('action', 'request.submitted')->sole();

    expect($audit->user_id)->toBe($world->developer->id)
        ->and($audit->metadata['channel'])->toBe('api');

    Queue::assertNothingPushed();
    Notification::assertSentTo($world->dba, QueryRequestSubmitted::class);
});

test('nothing runs until a reviewer approves: the API has no path around ADR-010', function () {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->dba))->postJson('/api/v1/requests', [
        'connection_id' => $world->connection->id,
        'sql' => 'SELECT 1',
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    Queue::assertNotPushed(ExecuteQueryRequest::class);
    expect(QueryRequest::sole()->status)->toBe(QueryRequestStatus::Pending);
});

test('a statement the SQL guard refuses gets 422 with the violations and creates nothing', function () {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->developer))->postJson('/api/v1/requests', [
        'connection_id' => $world->connection->id,
        'sql' => 'SELECT 1; DROP TABLE users',
    ])->assertStatus(422)->assertJsonStructure(['message', 'violations']);

    expect(QueryRequest::count())->toBe(0);
});

test('the body is validated', function (array $body) {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->developer))
        ->postJson('/api/v1/requests', $body + ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])
        ->assertStatus(422);
})->with([
    'no sql' => [['sql' => '']],
    'sql not a string' => [['sql' => ['SELECT 1']]],
    'sql too long' => [['sql' => 'SELECT '.str_repeat('1', 65536)]],
    'connection not an integer' => [['connection_id' => 'abc']],
    'title too long' => [['title' => str_repeat('a', 151)]],
    'reason too long' => [['reason' => str_repeat('a', 1001)]],
]);

test('a connection the caller cannot see is reported as missing', function () {
    $world = ApiWorld::make();
    $foreign = Connection::factory()->create(['team_id' => Team::factory()->create()->id]);

    $token = ApiWorld::token($world->developer);

    ApiWorld::as($token)->postJson('/api/v1/requests', ['connection_id' => $foreign->id, 'sql' => 'SELECT 1'])->assertNotFound();
    ApiWorld::as($token)->postJson('/api/v1/requests', ['connection_id' => 999999, 'sql' => 'SELECT 1'])->assertNotFound();

    expect(QueryRequest::count())->toBe(0);
});

test('a developer without a grant in force cannot submit', function () {
    $world = ApiWorld::make();
    $world->connection->grantedUsers()->updateExistingPivot($world->developer->id, ['expires_at' => now()->subMinute()]);

    ApiWorld::as(ApiWorld::token($world->developer))
        ->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])
        ->assertStatus(404);

    expect(QueryRequest::count())->toBe(0);
});

test('an auditor can see the connection but may not submit', function () {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->auditor))
        ->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])
        ->assertForbidden();

    expect(QueryRequest::count())->toBe(0);
});

test('a read-only grant cannot file a write statement through the API', function () {
    $world = ApiWorld::make();
    $world->connection->grantedUsers()->updateExistingPivot($world->developer->id, ['access_level' => 'read']);

    $token = ApiWorld::token($world->developer);

    ApiWorld::as($token)->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => "UPDATE users SET name = 'x' WHERE id = 1"])
        ->assertForbidden();
    ApiWorld::as($token)->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])
        ->assertCreated();
});

test('a suspended user cannot submit even with a token that predates the suspension', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->developer);

    $world->developer->forceFill(['suspended_at' => now()])->save();

    ApiWorld::as($token)->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])
        ->assertUnauthorized();

    expect(QueryRequest::count())->toBe(0);
});
