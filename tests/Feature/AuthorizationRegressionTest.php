<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Studio\QueryStudio;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Regression lock for authorization checks that are correct today but would
 * fail open if a single line went missing. Every test here is written so that
 * removing exactly one guard turns it red.
 */
const REGRESSION_SLACK_SECRET = 'regression-slack-signing-secret';

const REGRESSION_TEAMS_SECRET = 'regression-teams-hmac-secret';

/**
 * Two teams, one reviewer holding the DBA role in *both*, chat integrations
 * owned by the first team and a pending request owned by the second.
 *
 * The reviewer is deliberately a legitimate DBA for the foreign request, so
 * QueryRequestPolicy::review() would happily allow the decision: the only
 * thing standing between the webhook and a cross-team approval is the
 * controller's `where('team_id', $integration->team_id)` narrowing.
 *
 * @return array{0: Team, 1: Team, 2: User, 3: QueryRequest}
 */
function crossTeamChatSetup(): array
{
    $integrationTeam = Team::factory()->create();
    $foreignTeam = Team::factory()->create();

    $reviewer = User::factory()->create();
    $integrationTeam->users()->attach($reviewer, ['role' => 'dba']);
    $foreignTeam->users()->attach($reviewer, ['role' => 'dba']);

    $requester = User::factory()->create();
    $foreignTeam->users()->attach($requester, ['role' => 'developer']);

    ChatIntegration::create([
        'team_id' => $integrationTeam->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T999/B999/ZZZ',
        'signing_secret' => REGRESSION_SLACK_SECRET,
        'enabled' => true,
    ]);

    ChatIntegration::create([
        'team_id' => $integrationTeam->id,
        'provider' => 'teams',
        'webhook_url' => 'https://outlook.office.com/webhook/zzz',
        'signing_secret' => REGRESSION_TEAMS_SECRET,
        'enabled' => true,
    ]);

    ChatIdentity::create(['user_id' => $reviewer->id, 'provider' => 'slack', 'external_id' => 'U_CROSS_TEAM']);
    ChatIdentity::create(['user_id' => $reviewer->id, 'provider' => 'teams', 'external_id' => 'AAD_CROSS_TEAM']);

    $foreignRequest = QueryRequest::factory()->create([
        'team_id' => $foreignTeam->id,
        'connection_id' => Connection::factory()->create(['team_id' => $foreignTeam->id])->id,
        'user_id' => $requester->id,
        'status' => QueryRequestStatus::Pending,
    ]);

    return [$integrationTeam, $foreignTeam, $reviewer, $foreignRequest];
}

function postSignedSlackRegression(object $test, string $body)
{
    $timestamp = now()->getTimestamp();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", REGRESSION_SLACK_SECRET);

    parse_str($body, $parameters);

    return $test->call('POST', route('webhooks.slack'), $parameters, [], [], [
        'HTTP_X-Slack-Signature' => $signature,
        'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

function postSignedTeamsRegression(object $test, string $body)
{
    $timestamp = time();

    return $test->call('POST', route('webhooks.teams'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', "{$timestamp}:{$body}", REGRESSION_TEAMS_SECRET, true)),
        'HTTP_X_QUERYPROXY_TIMESTAMP' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('a slack integration cannot decide a request owned by another team', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, , , $foreignRequest] = crossTeamChatSetup();

    // A live token for the foreign request and an identity the policy would
    // accept: only the controller's team narrowing can refuse this.
    $token = ChatApprovalToken::issueFor($foreignRequest);

    $body = http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => 'U_CROSS_TEAM'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$foreignRequest->id}:{$token}"]],
    ])]);

    $response = postSignedSlackRegression($this, $body);

    $response->assertOk();

    expect($response->json('text'))->toBe('This query request no longer exists.')
        ->and($foreignRequest->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($foreignRequest->fresh()->reviewed_by)->toBeNull();

    Queue::assertNotPushed(ExecuteQueryRequest::class);
});

test('a teams integration cannot decide a request owned by another team', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, , , $foreignRequest] = crossTeamChatSetup();

    $body = json_encode([
        'action' => 'approve',
        'request_id' => $foreignRequest->id,
        'actor_id' => 'AAD_CROSS_TEAM',
        'token' => ChatApprovalToken::issueFor($foreignRequest),
    ]);

    postSignedTeamsRegression($this, $body)
        ->assertStatus(404)
        ->assertJsonPath('message', 'Query request not found.');

    expect($foreignRequest->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($foreignRequest->fresh()->reviewed_by)->toBeNull();

    Queue::assertNotPushed(ExecuteQueryRequest::class);
});

test('a dba may not review a request outside the team they are a dba in', function () {
    Queue::fake();
    Notification::fake();

    $ownTeam = Team::factory()->create();
    $foreignTeam = Team::factory()->create();

    // A real DBA — just not here. Membership of the foreign team is left out
    // on purpose so the only guard is QueryRequestPolicy's isDbaIn() clause.
    $foreignDba = User::factory()->create();
    $ownTeam->users()->attach($foreignDba, ['role' => 'dba']);

    $requester = User::factory()->create();
    $foreignTeam->users()->attach($requester, ['role' => 'developer']);

    $request = QueryRequest::factory()->create([
        'team_id' => $foreignTeam->id,
        'connection_id' => Connection::factory()->create(['team_id' => $foreignTeam->id])->id,
        'user_id' => $requester->id,
        'status' => QueryRequestStatus::Pending,
    ]);

    expect($foreignDba->can('review', $request))->toBeFalse();

    expect(fn () => app(ApprovalService::class)->approve($request, $foreignDba))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(ApprovalService::class)->reject($request->fresh(), $foreignDba, 'not mine'))
        ->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull();

    Queue::assertNotPushed(ExecuteQueryRequest::class);
});

test('a plain member of the request team may not review it either', function () {
    Queue::fake();
    Notification::fake();

    $team = Team::factory()->create();

    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);

    $requester = User::factory()->create();
    $team->users()->attach($requester, ['role' => 'developer']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $requester->id,
        'status' => QueryRequestStatus::Pending,
    ]);

    expect($auditor->can('review', $request))->toBeFalse();

    expect(fn () => app(ApprovalService::class)->approve($request, $auditor))
        ->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('an admin cannot re-decide a request that already reached a final state', function (string $status) {
    Queue::fake();
    Notification::fake();

    $team = Team::factory()->create();
    $requester = User::factory()->create();
    $team->users()->attach($requester, ['role' => 'developer']);

    $admin = User::factory()->create(['is_admin' => true]);

    $reviewer = User::factory()->create();
    $team->users()->attach($reviewer, ['role' => 'dba']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $requester->id,
        'status' => $status,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
        'review_channel' => 'web',
    ]);

    // Gate::before hands admins an unconditional pass everywhere *except* on a
    // QueryRequest argument, where the policy's state machine has the last word.
    expect($admin->can('review', $request))->toBeFalse()
        ->and($admin->can('cancel', $request))->toBeFalse();

    expect(fn () => app(ApprovalService::class)->approve($request, $admin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(ApprovalService::class)->reject($request->fresh(), $admin, 'changed my mind'))
        ->toThrow(AuthorizationException::class);

    $request->refresh();

    // Neither the execution job nor the approval trail may be rewritten.
    Queue::assertNotPushed(ExecuteQueryRequest::class);

    expect($request->status->value)->toBe($status)
        ->and($request->reviewed_by)->toBe($reviewer->id)
        ->and($request->review_channel)->toBe('web');
})->with(['completed', 'rejected', 'failed', 'cancelled']);

test('an admin still bypasses abilities that are not about a query request', function () {
    // Nothing here makes the admin a member: this is exactly the override
    // Gate::before must keep granting, so the lock above cannot be "fixed" by
    // deleting the bypass altogether.
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $admin = User::factory()->create(['is_admin' => true]);
    $outsider = User::factory()->create();

    expect($admin->can('view', $connection))->toBeTrue()
        ->and($admin->can('update', $connection))->toBeTrue()
        ->and($admin->can('delete', $connection))->toBeTrue()
        ->and($admin->can('use', $connection))->toBeTrue();

    expect($outsider->can('view', $connection))->toBeFalse()
        ->and($outsider->can('update', $connection))->toBeFalse()
        ->and($outsider->can('delete', $connection))->toBeFalse()
        ->and($outsider->can('use', $connection))->toBeFalse();
});

test('connection use is open to team dbas and to granted developers only', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $dba = User::factory()->create();
    $grantedDeveloper = User::factory()->create();
    $ungrantedDeveloper = User::factory()->create();
    $grantedAuditor = User::factory()->create();
    $grantedOutsider = User::factory()->create();

    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($grantedDeveloper, ['role' => 'developer']);
    $team->users()->attach($ungrantedDeveloper, ['role' => 'developer']);
    $team->users()->attach($grantedAuditor, ['role' => 'auditor']);

    $connection->grantedUsers()->attach([$grantedDeveloper->id, $grantedAuditor->id, $grantedOutsider->id]);

    // A DBA needs no grant; everyone else needs both the developer role in the
    // owning team and an explicit grant.
    expect($dba->can('use', $connection))->toBeTrue()
        ->and($grantedDeveloper->can('use', $connection))->toBeTrue()
        ->and($ungrantedDeveloper->can('use', $connection))->toBeFalse()
        ->and($grantedAuditor->can('use', $connection))->toBeFalse()
        ->and($grantedOutsider->can('use', $connection))->toBeFalse();
});

test('a developer whose connection grant was revoked can no longer submit', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);

    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    $connection->grantedUsers()->detach($developer);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1')
        ->call('submit')
        ->assertForbidden();

    expect(QueryRequest::count())->toBe(0);
});

test('a developer cannot submit through a connection owned by another team', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    session(['current_team_id' => $team->id]);

    // Granted, and a developer — but in the wrong team, so the team scope has
    // to refuse the connection before the policy is ever consulted.
    $foreignTeam = Team::factory()->create();
    $foreignConnection = Connection::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignTeam->users()->attach($developer, ['role' => 'developer']);
    $foreignConnection->grantedUsers()->attach($developer);

    $component = Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $foreignConnection->id)
        ->set('sql', 'SELECT 1');

    expect(fn () => $component->call('submit'))->toThrow(ModelNotFoundException::class);

    expect(QueryRequest::count())->toBe(0);
});
