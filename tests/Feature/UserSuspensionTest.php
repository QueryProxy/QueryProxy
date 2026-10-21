<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Admin\Users;
use App\Models\AuditLog;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestSubmitted;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Approvals\ApprovalService;
use App\Services\Users\UserLifecycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

const SUSPEND_SLACK_SECRET = 'suspend-slack-signing-secret';

const SUSPEND_TEAMS_SECRET = 'suspend-teams-hmac-secret';

/** @return array{0: Team, 1: User, 2: User, 3: QueryRequest} team, dba, requester, pending request */
function suspensionSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create(['password' => 'secret-password']);
    $developer = User::factory()->create(['password' => 'secret-password']);
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
    ]);

    return [$team, $dba, $developer, $request];
}

test('a suspended user cannot log in', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    app(UserLifecycle::class)->suspend($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();

    app(UserLifecycle::class)->unsuspend($user->fresh());

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('dashboard'));
});

test('a suspended user is stopped at the two-factor step too', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();

    $user = User::factory()->create(['password' => 'secret-password']);
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('two-factor.challenge'));

    app(UserLifecycle::class)->suspend($user->fresh());

    $this->post('/two-factor-challenge', ['code' => $engine->getCurrentOtp($secret)])
        ->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

test('suspending ends a live session', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
    $this->get(route('dashboard'))->assertOk();

    app(UserLifecycle::class)->suspend($user->fresh());

    // A real second request resolves the user afresh; drop the guard's in-process cache.
    Auth::forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

test('suspending drops the stored session rows and rotates the remember token', function () {
    $user = User::factory()->create();
    $before = $user->remember_token;

    DB::table('sessions')->insert([
        'id' => 'session-of-suspended-user',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    app(UserLifecycle::class)->suspend($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($before)
        ->and($user->fresh()->isSuspended())->toBeTrue()
        ->and(AuditLog::where('action', 'user.suspended')->count())->toBe(1);
});

test('suspending a user leaves their pending requests pending', function () {
    [, , $developer, $request] = suspensionSetup();

    app(UserLifecycle::class)->suspend($developer);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and(AuditLog::where('action', 'request.cancelled')->count())->toBe(0);
});

test('a request of a suspended user is still decided and executed under the normal rules', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, $developer, $request] = suspensionSetup();

    app(UserLifecycle::class)->suspend($developer);

    app(ApprovalService::class)->approve($request, $dba);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued);

    Queue::assertPushed(ExecuteQueryRequest::class);
});

test('a suspended chat identity no longer resolves on slack', function () {
    Queue::fake();
    Notification::fake();

    [$team, $dba, , $request] = suspensionSetup();

    ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T0/B0/X',
        'signing_secret' => SUSPEND_SLACK_SECRET, 'enabled' => true,
    ]);
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_SUSPENDED']);

    app(UserLifecycle::class)->suspend($dba);

    $token = ChatApprovalToken::issueFor($request);
    $body = http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => 'U_SUSPENDED'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);

    $timestamp = now()->getTimestamp();
    parse_str($body, $parameters);

    $response = $this->call('POST', route('webhooks.slack'), $parameters, [], [], [
        'HTTP_X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", SUSPEND_SLACK_SECRET),
        'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);

    expect($response->json('text'))->toContain('not linked')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    Queue::assertNotPushed(ExecuteQueryRequest::class);
});

test('a suspended chat identity no longer resolves on teams', function () {
    Queue::fake();
    Notification::fake();

    [$team, $dba, , $request] = suspensionSetup();

    ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'teams', 'webhook_url' => 'https://outlook.office.com/webhook/x',
        'signing_secret' => SUSPEND_TEAMS_SECRET, 'enabled' => true,
    ]);
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_SUSPENDED']);

    app(UserLifecycle::class)->suspend($dba);

    $body = json_encode([
        'action' => 'approve',
        'request_id' => $request->id,
        'actor_id' => 'AAD_SUSPENDED',
        'token' => ChatApprovalToken::issueFor($request),
    ]);
    $timestamp = time();

    $this->call('POST', route('webhooks.teams'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', "{$timestamp}:{$body}", SUSPEND_TEAMS_SECRET, true)),
        'HTTP_X_QUERYPROXY_TIMESTAMP' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(422);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a suspended dba no longer gets approval notifications', function () {
    Notification::fake();

    [$team, $dba, , $request] = suspensionSetup();
    $activeDba = User::factory()->create();
    $team->users()->attach($activeDba, ['role' => 'dba']);

    app(UserLifecycle::class)->suspend($dba);

    ApprovalNotifier::requestSubmitted($request);

    Notification::assertSentTo($activeDba, QueryRequestSubmitted::class);
    Notification::assertNotSentTo($dba, QueryRequestSubmitted::class);
});

test('an admin can suspend and resume a user from the users screen', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $component = Livewire::actingAs($admin)->test(Users::class);

    $component->call('suspendUser', $user->id);
    expect($user->fresh()->isSuspended())->toBeTrue();

    $component->call('unsuspendUser', $user->id);
    expect($user->fresh()->isSuspended())->toBeFalse()
        ->and(AuditLog::where('action', 'user.unsuspended')->count())->toBe(1);
});

test('an admin cannot suspend themselves and a non-admin cannot suspend anyone', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    Livewire::actingAs($admin)->test(Users::class)
        ->call('suspendUser', $admin->id)
        ->assertHasErrors('email');

    expect($admin->fresh()->isSuspended())->toBeFalse();

    Livewire::actingAs($other)->test(Users::class)->call('suspendUser', $admin->id)->assertForbidden();
});
