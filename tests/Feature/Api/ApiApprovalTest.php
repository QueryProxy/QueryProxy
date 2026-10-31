<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiWorld;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

test('a dba approves another user\'s pending request and execution is queued', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);

    ApiWorld::as(ApiWorld::token($world->dba))->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.review_channel', 'api');

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->reviewed_by)->toBe($world->dba->id);

    Queue::assertPushed(ExecuteQueryRequest::class);

    $audit = AuditLog::where('action', 'request.approved')->sole();

    expect($audit->user_id)->toBe($world->dba->id)
        ->and($audit->metadata['channel'])->toBe('api')
        ->and($audit->metadata['override'])->toBeFalse();
});

test('a dba cannot approve their own request through the API (ADR-008)', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->dba);

    ApiWorld::as(ApiWorld::token($world->dba))->postJson("/api/v1/requests/{$request->id}/approve")->assertForbidden();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
    Queue::assertNothingPushed();
});

test('an approval that would be an override is refused with 403 and the link to the web form', function (string $who, string $owner) {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->{$owner});

    $response = ApiWorld::as(ApiWorld::token($world->{$who}))->postJson("/api/v1/requests/{$request->id}/approve");

    $response->assertForbidden()->assertJsonPath('override_url', route('requests.override', $request));

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and(AuditLog::where('action', 'request.approved')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'admin who is not dba of the team' => ['admin', 'developer'],
    'admin approving their own request' => ['admin', 'admin'],
]);

test('an admin who is also dba of the team approves another user\'s request without an override', function () {
    $world = ApiWorld::make();
    $world->team->users()->attach($world->admin, ['role' => 'dba']);
    $request = $world->pendingRequest($world->developer);

    ApiWorld::as(ApiWorld::token($world->admin))->postJson("/api/v1/requests/{$request->id}/approve")->assertOk();

    expect(AuditLog::where('action', 'request.approved')->sole()->metadata['override'])->toBeFalse();
});

test('an admin who is also dba of the team still cannot approve their own request', function () {
    $world = ApiWorld::make();
    $world->team->users()->attach($world->admin, ['role' => 'dba']);
    $request = $world->pendingRequest($world->admin);

    ApiWorld::as(ApiWorld::token($world->admin))->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('override_url', route('requests.override', $request));
});

test('a developer, an auditor and a dba of another team cannot approve or reject', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);
    $otherDba = User::factory()->create();
    Team::factory()->create()->users()->attach($otherDba, ['role' => 'dba']);

    foreach ([$world->auditor, $otherDba, User::factory()->create()] as $user) {
        $token = ApiWorld::token($user);

        ApiWorld::as($token)->postJson("/api/v1/requests/{$request->id}/approve")->assertForbidden();
        ApiWorld::as($token)->postJson("/api/v1/requests/{$request->id}/reject", ['reason' => 'no'])->assertForbidden();
    }

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a dba rejects with a reason; the reason is required', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);
    $token = ApiWorld::token($world->dba);

    ApiWorld::as($token)->postJson("/api/v1/requests/{$request->id}/reject")->assertStatus(422);
    ApiWorld::as($token)->postJson("/api/v1/requests/{$request->id}/reject", ['reason' => str_repeat('a', 1001)])->assertStatus(422);

    ApiWorld::as($token)->postJson("/api/v1/requests/{$request->id}/reject", ['reason' => 'Too broad'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Too broad')
        ->assertJsonPath('data.review_channel', 'api');

    expect(AuditLog::where('action', 'request.rejected')->sole()->metadata)->toBe(['channel' => 'api', 'reason' => 'Too broad']);
    Queue::assertNothingPushed();
});

test('a dba cannot reject their own request either', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->dba);

    ApiWorld::as(ApiWorld::token($world->dba))->postJson("/api/v1/requests/{$request->id}/reject", ['reason' => 'x'])->assertForbidden();
});

test('the requester cancels their own pending request; nobody else can', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);

    ApiWorld::as(ApiWorld::token($world->dba))->postJson("/api/v1/requests/{$request->id}/cancel")->assertForbidden();
    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    ApiWorld::as(ApiWorld::token($world->developer))->postJson("/api/v1/requests/{$request->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $audit = AuditLog::where('action', 'request.cancelled')->sole();

    expect($audit->user_id)->toBe($world->developer->id)
        ->and($audit->metadata)->toBe(['channel' => 'api']);
});

test('a request that was already decided answers 409 and nothing changes', function (string $action) {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer, ['status' => QueryRequestStatus::Rejected]);
    $actor = $action === 'cancel' ? $world->developer : $world->dba;

    ApiWorld::as(ApiWorld::token($actor))->postJson("/api/v1/requests/{$request->id}/{$action}", ['reason' => 'x'])->assertStatus(409);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Rejected);
    Queue::assertNothingPushed();
})->with(['approve', 'reject', 'cancel']);

test('someone who cannot view the request learns nothing about its state: 403, not 409', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->dba, ['status' => QueryRequestStatus::Rejected]);

    ApiWorld::as(ApiWorld::token($world->developer))->postJson("/api/v1/requests/{$request->id}/approve")->assertForbidden();
});

test('an unknown request is 404', function () {
    $world = ApiWorld::make();

    ApiWorld::as(ApiWorld::token($world->dba))->postJson('/api/v1/requests/999999/approve')->assertNotFound();
});

test('cancelling through the service without a channel records web', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);

    app(ApprovalService::class)->cancel($request, $world->developer);

    expect(AuditLog::where('action', 'request.cancelled')->sole()->metadata)->toBe(['channel' => 'web']);
});
