<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Approvals\Override;
use App\Livewire\Studio\QueryStudio;
use App\Models\AuditLog;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const OVERRIDE_SLACK_SECRET = 'override-slack-signing-secret';

const OVERRIDE_TEAMS_SECRET = 'override-teams-hmac-secret';

/**
 * A team with a regular DBA and a developer's pending request, plus a system
 * admin who is a plain member of the team (so chat identities resolve): the
 * admin approving it is an override, the DBA approving it is not.
 *
 * @return array{0: Team, 1: User, 2: User, 3: QueryRequest}
 */
function overrideSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $team->users()->attach($admin, ['role' => 'developer']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'note' => 'Customer 42 asked for a data fix',
    ]);

    session(['current_team_id' => $team->id]);

    return [$team, $dba, $admin, $request];
}

function overrideChatIntegrations(Team $team): void
{
    ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T1/B1/O',
        'signing_secret' => OVERRIDE_SLACK_SECRET, 'enabled' => true,
    ]);
    ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'teams', 'webhook_url' => 'https://outlook.office.com/webhook/o',
        'signing_secret' => OVERRIDE_TEAMS_SECRET, 'enabled' => true,
    ]);
}

function overrideSlackPress(object $test, QueryRequest $request, string $slackUserId, string $token)
{
    $body = http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => $slackUserId],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);

    $timestamp = now()->getTimestamp();
    parse_str($body, $parameters);

    return $test->call('POST', route('webhooks.slack'), $parameters, [], [], [
        'HTTP_X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", OVERRIDE_SLACK_SECRET),
        'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

function overrideTeamsAction(object $test, array $payload)
{
    $body = json_encode($payload);
    $timestamp = time();

    return $test->call('POST', route('webhooks.teams'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', "{$timestamp}:{$body}", OVERRIDE_TEAMS_SECRET, true)),
        'HTTP_X_QUERYPROXY_TIMESTAMP' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('an override without a reason is refused by the service and changes nothing', function (?string $reason) {
    Queue::fake();
    Notification::fake();

    [, , $admin, $request] = overrideSetup();

    expect(fn () => app(ApprovalService::class)->approve($request, $admin, 'web', $reason))
        ->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull()
        ->and(AuditLog::where('action', 'request.approved')->count())->toBe(0);

    Queue::assertNotPushed(ExecuteQueryRequest::class);
})->with([[null], [''], ['   ']]);

test('an override with a reason is applied and the reason is audited next to the override flag', function () {
    Queue::fake();
    Notification::fake();

    [, , $admin, $request] = overrideSetup();

    app(ApprovalService::class)->approve($request, $admin, 'web', '  Production incident 4711  ');

    $log = AuditLog::where('action', 'request.approved')->sole();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($log->metadata['override'])->toBeTrue()
        ->and($log->metadata['reason'])->toBe('Production incident 4711');

    Queue::assertPushed(ExecuteQueryRequest::class);
});

test('a reason longer than the limit is refused', function () {
    [, , $admin, $request] = overrideSetup();

    expect(fn () => app(ApprovalService::class)->approve($request, $admin, 'web', str_repeat('x', ApprovalService::MAX_REASON_LENGTH + 1)))
        ->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('an admin approving their own request is an override too', function () {
    [, , $admin] = overrideSetup();

    $own = QueryRequest::factory()->create([
        'team_id' => $admin->teams()->first()->id,
        'connection_id' => Connection::factory()->create(['team_id' => $admin->teams()->first()->id])->id,
        'user_id' => $admin->id,
    ]);

    expect(app(ApprovalService::class)->isOverride($own, $admin))->toBeTrue()
        ->and(fn () => app(ApprovalService::class)->approve($own, $admin))->toThrow(ValidationException::class);
});

test('a regular dba approval needs no reason and carries none', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, , $request] = overrideSetup();

    app(ApprovalService::class)->approve($request, $dba);

    $log = AuditLog::where('action', 'request.approved')->sole();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($log->metadata['override'])->toBeFalse()
        ->and($log->metadata)->not->toHaveKey('reason');
});

test('a slack approve press that would be an override is not applied and answers with the web form link', function () {
    Queue::fake();
    Notification::fake();

    [$team, , $admin, $request] = overrideSetup();
    overrideChatIntegrations($team);
    ChatIdentity::create(['user_id' => $admin->id, 'provider' => 'slack', 'external_id' => 'U_ADMIN']);

    $token = ChatApprovalToken::issueFor($request);

    $response = overrideSlackPress($this, $request, 'U_ADMIN', $token);

    $response->assertOk();

    expect($response->json('text'))->toContain(route('requests.override', $request))
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull()
        ->and(ChatApprovalToken::isValidFor($request, $token))->toBeTrue()
        ->and(AuditLog::where('action', 'request.approved')->count())->toBe(0);

    Queue::assertNotPushed(ExecuteQueryRequest::class);
});

test('a slack approve press by a regular dba is still applied', function () {
    Queue::fake();
    Notification::fake();

    [$team, $dba, , $request] = overrideSetup();
    overrideChatIntegrations($team);
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_DBA']);

    overrideSlackPress($this, $request, 'U_DBA', ChatApprovalToken::issueFor($request))->assertOk();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued);
});

test('a teams approve that would be an override without a reason is refused and keeps the token', function () {
    Queue::fake();
    Notification::fake();

    [$team, , $admin, $request] = overrideSetup();
    overrideChatIntegrations($team);
    ChatIdentity::create(['user_id' => $admin->id, 'provider' => 'teams', 'external_id' => 'AAD_ADMIN']);

    $token = ChatApprovalToken::issueFor($request);
    $payload = ['action' => 'approve', 'request_id' => $request->id, 'actor_id' => 'AAD_ADMIN', 'token' => $token];

    overrideTeamsAction($this, $payload)
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and(ChatApprovalToken::isValidFor($request, $token))->toBeTrue();

    // The same card answered again, now with the reason field.
    overrideTeamsAction($this, $payload + ['reason' => 'Break-glass: DBA on leave'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $log = AuditLog::where('action', 'request.approved')->sole();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($log->metadata['channel'])->toBe('teams')
        ->and($log->metadata['override'])->toBeTrue()
        ->and($log->metadata['reason'])->toBe('Break-glass: DBA on leave');

    Queue::assertPushed(ExecuteQueryRequest::class);
});

test('the web override form requires a reason and then applies the override', function () {
    Queue::fake();
    Notification::fake();

    [, , $admin, $request] = overrideSetup();

    $component = Livewire::actingAs($admin)
        ->test(Override::class, ['queryRequest' => $request])
        ->assertSee('Customer 42 asked for a data fix');

    $component->set('reason', '')->call('approve')->assertHasErrors('reason');
    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    $component->set('reason', 'Chat override from Slack')->call('approve')
        ->assertHasNoErrors()
        ->assertRedirect(route('requests.show', $request));

    $log = AuditLog::where('action', 'request.approved')->sole();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata['reason'])->toBe('Chat override from Slack');
});

test('the override form is closed to people who may not review the request', function () {
    [$team, , , $request] = overrideSetup();

    $developer = $request->requester;
    $outsider = User::factory()->create();

    Livewire::actingAs($developer)->test(Override::class, ['queryRequest' => $request])->assertForbidden();
    Livewire::actingAs($outsider)->test(Override::class, ['queryRequest' => $request])->assertForbidden();

    $this->actingAs($outsider)->get(route('requests.override', $request))->assertForbidden();
});

test('a regular dba is sent past the override form', function () {
    [, $dba, , $request] = overrideSetup();

    Livewire::actingAs($dba)
        ->test(Override::class, ['queryRequest' => $request])
        ->assertRedirect(route('requests.show', $request));
});

test('the approvals list asks an admin for a reason before an override', function () {
    Queue::fake();
    Notification::fake();

    [, , $admin, $request] = overrideSetup();

    $component = Livewire::actingAs($admin)->test(ApprovalsIndex::class)
        ->assertSee('Customer 42 asked for a data fix');

    $component->call('approve', $request->id);
    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    $component->call('startOverride', $request->id)
        ->set('reason', 'Emergency fix')
        ->call('approve', $request->id);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued);
});

test('the studio saves the optional request reason', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT * FROM customers')
        ->set('note', '  Support ticket 99  ')
        ->call('submit')
        ->assertHasNoErrors();

    expect(QueryRequest::sole()->note)->toBe('Support ticket 99');
});
