<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ApiWorld;

function apiCompletedRequest(ApiWorld $world, User $requester, array $rows = [[1, 'alice'], [2, '=cmd']]): QueryRequest
{
    Storage::fake('local');

    $path = "results/{$world->team->id}/api-test.ndjson";

    Storage::disk('local')->put($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));

    return $world->pendingRequest($requester, [
        'status' => QueryRequestStatus::Completed,
        'result_disk' => 'local',
        'result_path' => $path,
        'result_row_count' => count($rows),
        'result_truncated' => false,
        'result_columns' => ['id', 'name'],
        'executed_at' => now(),
    ]);
}

test('the requester, the team dba and an admin can read a request; the raw SQL is theirs only', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer, ['sql_original' => "SELECT * FROM users WHERE email = 'a@b.co'"]);

    foreach ([$world->developer, $world->dba, $world->admin] as $user) {
        ApiWorld::as(ApiWorld::token($user))->getJson("/api/v1/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.sql_original', "SELECT * FROM users WHERE email = 'a@b.co'")
            ->assertJsonPath('data.sql_redacted', null);
    }
});

test('an auditor reads the request metadata but never the literal SQL values', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer, [
        'sql_original' => "SELECT * FROM users WHERE email = 'a@b.co'",
        'sql_prepared' => "SELECT * FROM users WHERE email = 'a@b.co' LIMIT 1000",
    ]);

    $response = ApiWorld::as(ApiWorld::token($world->auditor))->getJson("/api/v1/requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.sql_original', null)
        ->assertJsonPath('data.sql_prepared', null);

    expect($response->getContent())->not->toContain('a@b.co');
});

test('an auditor gets neither the column names nor the truncation flag of a result: they are result data', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->developer);
    // A column named after an expression carries its literal value.
    $request->update(['result_columns' => ["email = 'ceo@acme.com'", 'id'], 'result_truncated' => true]);

    $auditor = ApiWorld::as(ApiWorld::token($world->auditor))->getJson("/api/v1/requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.result.available', true)
        ->assertJsonPath('data.result.row_count', 2)
        ->assertJsonPath('data.result.columns', null)
        ->assertJsonPath('data.result.truncated', null);

    expect($auditor->getContent())->not->toContain('ceo@acme.com');

    foreach ([$world->developer, $world->dba, $world->admin] as $user) {
        ApiWorld::as(ApiWorld::token($user))->getJson("/api/v1/requests/{$request->id}")
            ->assertJsonPath('data.result.columns', ["email = 'ceo@acme.com'", 'id'])
            ->assertJsonPath('data.result.truncated', true);
    }
});

test('someone who may not view the request gets 403, an unknown id 404', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->dba);
    $stranger = User::factory()->create();

    ApiWorld::as(ApiWorld::token($world->developer))->getJson("/api/v1/requests/{$request->id}")->assertForbidden();
    ApiWorld::as(ApiWorld::token($stranger))->getJson("/api/v1/requests/{$request->id}")->assertForbidden();
    ApiWorld::as(ApiWorld::token($world->dba))->getJson('/api/v1/requests/999999')->assertNotFound();
});

test('the result streams as NDJSON by default, one row per line', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->developer);

    $response = ApiWorld::as(ApiWorld::token($world->developer))->get("/api/v1/requests/{$request->id}/result");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/x-ndjson')
        ->assertHeader('X-QueryProxy-Row-Count', '2')
        ->assertHeader('X-QueryProxy-Truncated', 'false');

    expect($response->streamedContent())->toBe("[1,\"alice\"]\n[2,\"=cmd\"]\n");

    $audit = AuditLog::where('action', 'result.downloaded')->sole();

    expect($audit->user_id)->toBe($world->developer->id)
        ->and($audit->metadata)->toBe(['channel' => 'api', 'format' => 'ndjson']);
});

test('Accept text/csv returns CSV with a header row and formula-safe cells', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->developer);

    $response = ApiWorld::as(ApiWorld::token($world->dba))
        ->get("/api/v1/requests/{$request->id}/result", ['Accept' => 'text/csv']);

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    expect($response->streamedContent())->toBe("id,name\n1,alice\n2,'=cmd\n")
        ->and(AuditLog::where('action', 'result.downloaded')->sole()->metadata['format'])->toBe('csv');
});

test('a large result is sent as a streamed response', function () {
    $world = ApiWorld::make();
    $rows = array_map(fn ($i) => [$i, str_repeat('x', 200)], range(1, 2000));
    $request = apiCompletedRequest($world, $world->developer, $rows);

    $response = ApiWorld::as(ApiWorld::token($world->developer))->get("/api/v1/requests/{$request->id}/result");

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and(substr_count($response->streamedContent(), "\n"))->toBe(2000);
});

test('an auditor gets 403 on the result (ADR-009), and no download is recorded', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->developer);

    ApiWorld::as(ApiWorld::token($world->auditor))->getJson("/api/v1/requests/{$request->id}/result")->assertForbidden();

    expect(AuditLog::where('action', 'result.downloaded')->count())->toBe(0);
});

test('another developer and a stranger get 403 on the result', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->dba);

    ApiWorld::as(ApiWorld::token($world->developer))->getJson("/api/v1/requests/{$request->id}/result")->assertForbidden();
    ApiWorld::as(ApiWorld::token(User::factory()->create()))->getJson("/api/v1/requests/{$request->id}/result")->assertForbidden();
});

test('a request without a stored result answers 409, a result file that is gone 404', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->developer);

    $pending = $world->pendingRequest($world->developer);
    ApiWorld::as($token)->getJson("/api/v1/requests/{$pending->id}/result")->assertStatus(409);

    $completed = apiCompletedRequest($world, $world->developer);
    Storage::disk('local')->delete($completed->result_path);
    ApiWorld::as($token)->getJson("/api/v1/requests/{$completed->id}/result")->assertNotFound();
});

test('the request resource tells how to read the result', function () {
    $world = ApiWorld::make();
    $request = apiCompletedRequest($world, $world->developer);

    ApiWorld::as(ApiWorld::token($world->developer))->getJson("/api/v1/requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.result.available', true)
        ->assertJsonPath('data.result.row_count', 2)
        ->assertJsonPath('data.result.columns', ['id', 'name']);
});
