<?php

use App\Enums\QueryRequestStatus;
use App\Livewire\Settings\ChatOps;
use App\Livewire\Studio\QueryStudio;
use App\Models\AuditLog;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

const SLACK_SECRET = 'test-slack-signing-secret';
const TEAMS_SECRET = 'test-teams-hmac-secret';

function chatSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => SLACK_SECRET,
        'enabled' => true,
    ]);

    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'teams',
        'webhook_url' => 'https://outlook.office.com/webhook/xxx',
        'signing_secret' => TEAMS_SECRET,
        'enabled' => true,
    ]);

    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_DBA']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
    ]);

    return [$team, $dba, $developer, $request];
}

/**
 * Mint the single-use action token ChatNotifier would have embedded in the
 * message for this request.
 */
function chatToken(QueryRequest $request): string
{
    return ChatApprovalToken::issueFor($request);
}

/**
 * @param  string|null  $token  null issues a fresh valid token; pass '' to
 *                              build a legacy, token-less button value.
 */
function slackPayload(QueryRequest $request, string $action, string $slackUserId, ?string $token = null): string
{
    $token ??= chatToken($request);
    $value = $token === '' ? "{$action}:{$request->id}" : "{$action}:{$request->id}:{$token}";

    return http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => $slackUserId],
        'actions' => [['action_id' => "queryproxy_{$action}", 'value' => $value]],
    ])]);
}

function postSignedSlack(object $test, string $body, ?string $secret = null, ?int $timestamp = null)
{
    $timestamp ??= now()->getTimestamp();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret ?? SLACK_SECRET);

    parse_str($body, $parameters);

    return $test->call('POST', route('webhooks.slack'), $parameters, [], [], [
        'HTTP_X-Slack-Signature' => $signature,
        'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

test('a correctly signed slack approval executes the decision', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, $dba, , $request] = chatSetup();

    $response = postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA'));

    $response->assertOk();

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->reviewed_by)->toBe($dba->id)
        ->and($request->review_channel)->toBe('slack')
        ->and($response->json('replace_original'))->toBeTrue();
});

test('a forged slack signature is rejected with 401 and no state change', function () {
    [, , , $request] = chatSetup();

    postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA'), 'wrong-secret')
        ->assertUnauthorized();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('stale slack timestamps are rejected (replay protection)', function () {
    [, , , $request] = chatSetup();

    postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA'), null, now()->getTimestamp() - 600)
        ->assertUnauthorized();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('unmapped slack users get an ephemeral error and nothing changes', function () {
    [, , , $request] = chatSetup();

    $response = postSignedSlack($this, slackPayload($request, 'approve', 'U_UNKNOWN'));

    $response->assertOk();

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('slack rejection records the decision', function () {
    Notification::fake();
    Http::fake();

    [, $dba, , $request] = chatSetup();

    postSignedSlack($this, slackPayload($request, 'reject', 'U_DBA'))->assertOk();

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Rejected)
        ->and($request->rejection_reason)->toContain($dba->name);
});

test('slack users cannot approve their own requests', function () {
    [$team, $dba] = chatSetup();

    $own = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $dba->id,
    ]);

    $response = postSignedSlack($this, slackPayload($own, 'approve', 'U_DBA'));

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($own->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a signed slack approval naming a colleague is refused without a live action token', function () {
    [$team, , , $request] = chatSetup();

    $colleague = User::factory()->create();
    $team->users()->attach($colleague, ['role' => 'dba']);
    ChatIdentity::create(['user_id' => $colleague->id, 'provider' => 'slack', 'external_id' => 'U_COLLEAGUE']);

    // The attacker holds the signing secret (they rotated it, or it leaked) and
    // knows the colleague's Slack member ID, which is public in the workspace —
    // but they never saw the message QueryProxy actually posted.
    $response = postSignedSlack($this, slackPayload(
        $request, 'approve', 'U_COLLEAGUE', str_repeat('deadbeef', 5),
    ));

    $response->assertOk();

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($response->json('text'))->toContain('could not be verified')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->fresh()->reviewed_by)->toBeNull();
});

test('slack buttons carrying no action token are refused', function () {
    [, , , $request] = chatSetup();

    $response = postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA', ''));

    $response->assertOk();

    expect($response->json('text'))->toContain('could not be verified')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a slack action token cannot be spent twice', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, , , $request] = chatSetup();

    $token = chatToken($request);

    postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA', $token))->assertOk();

    $replay = postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA', $token));

    expect($replay->json('text'))->toContain('could not be verified')
        ->and(ChatApprovalToken::where('query_request_id', $request->id)->whereNull('used_at')->count())->toBe(0);
});

test('an expired slack action token is refused', function () {
    [, , , $request] = chatSetup();

    $token = chatToken($request);
    ChatApprovalToken::where('query_request_id', $request->id)->update(['expires_at' => now()->subMinute()]);

    $response = postSignedSlack($this, slackPayload($request, 'approve', 'U_DBA', $token));

    expect($response->json('text'))->toContain('could not be verified')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a slack identity mapped to a user outside the team is refused', function () {
    [, , , $request] = chatSetup();

    $outsider = User::factory()->create();
    ChatIdentity::create(['user_id' => $outsider->id, 'provider' => 'slack', 'external_id' => 'U_OUTSIDER']);

    $response = postSignedSlack($this, slackPayload($request, 'approve', 'U_OUTSIDER'));

    // The controller's own team check, not the policy's — the two have
    // deliberately different wording so this test can tell them apart.
    expect($response->json('text'))->toBe('You are not allowed to decide on this request.')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

function teamsHeaders(string $body, string $secret = TEAMS_SECRET, ?int $timestamp = null): array
{
    $timestamp ??= time();

    return [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', "{$timestamp}:{$body}", $secret, true)),
        'HTTP_X_QUERYPROXY_TIMESTAMP' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ];
}

test('a correctly signed teams action approves the request', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, $dba, , $request] = chatSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_DBA']);

    $body = json_encode([
        'action' => 'approve',
        'request_id' => $request->id,
        'actor_id' => 'AAD_DBA',
        'token' => chatToken($request),
    ]);

    $this->call('POST', route('webhooks.teams'), [], [], [], teamsHeaders($body), $body)->assertOk();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->fresh()->review_channel)->toBe('teams');
});

test('teams requests with a bad hmac are rejected', function () {
    [, $dba, , $request] = chatSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_DBA']);

    $body = json_encode(['action' => 'approve', 'request_id' => $request->id, 'actor_id' => 'AAD_DBA', 'token' => chatToken($request)]);

    $this->call('POST', route('webhooks.teams'), [], [], [], teamsHeaders($body, 'wrong'), $body)
        ->assertUnauthorized();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('teams requests with a stale timestamp are rejected even when correctly signed', function () {
    [, $dba, , $request] = chatSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_DBA']);

    $body = json_encode(['action' => 'approve', 'request_id' => $request->id, 'actor_id' => 'AAD_DBA', 'token' => chatToken($request)]);

    $this->call('POST', route('webhooks.teams'), [], [], [], teamsHeaders($body, timestamp: time() - 600), $body)
        ->assertUnauthorized();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('teams actors must be linked through a chat identity, not a self-declared email', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, , $request] = chatSetup();

    // No teams ChatIdentity linked: even a valid signature and a live action
    // token with a real user id must fail.
    $body = json_encode(['action' => 'approve', 'request_id' => $request->id, 'actor_id' => 'AAD_UNLINKED', 'token' => chatToken($request)]);

    $this->call('POST', route('webhooks.teams'), [], [], [], teamsHeaders($body), $body)
        ->assertStatus(422);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('submitting a request posts to configured chat webhooks', function () {
    Http::fake();
    Notification::fake();

    [$team, , $developer] = chatSetup();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1')
        ->call('submit');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com')
        && str_contains($request->body(), 'queryproxy_approve'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'outlook.office.com'));
});

test('a teams action without a valid token is refused even with a correct hmac', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, , $request] = chatSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_DBA']);

    $body = json_encode([
        'action' => 'approve',
        'request_id' => $request->id,
        'actor_id' => 'AAD_DBA',
        'token' => str_repeat('deadbeef', 5),
    ]);

    $this->call('POST', route('webhooks.teams'), [], [], [], teamsHeaders($body), $body)
        ->assertStatus(422);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a dba cannot set the slack signing secret', function () {
    [$team, $dba] = chatSetup();
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($dba)
        ->test(ChatOps::class)
        ->set('slackSigningSecret', 'attacker-chosen-secret')
        ->call('saveSlack')
        ->assertHasErrors('slackSigningSecret');

    expect(ChatIntegration::where('team_id', $team->id)->where('provider', 'slack')->first()->signing_secret)
        ->toBe(SLACK_SECRET);
});

test('a dba can still update the webhook url and the enabled flag', function () {
    [$team, $dba] = chatSetup();
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($dba)
        ->test(ChatOps::class)
        ->set('slackWebhookUrl', 'https://hooks.slack.com/services/T111/B111/YYY')
        ->set('slackEnabled', false)
        ->call('saveSlack')
        ->assertHasNoErrors();

    $integration = ChatIntegration::where('team_id', $team->id)->where('provider', 'slack')->first();

    expect($integration->webhook_url)->toBe('https://hooks.slack.com/services/T111/B111/YYY')
        ->and($integration->enabled)->toBeFalse()
        ->and($integration->signing_secret)->toBe(SLACK_SECRET);
});

test('an admin cannot rotate the slack signing secret without the current one', function () {
    [$team] = chatSetup();
    $admin = User::factory()->create(['is_admin' => true]);
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($admin)
        ->test(ChatOps::class)
        ->set('slackCurrentSigningSecret', 'not-the-current-secret')
        ->set('slackSigningSecret', 'rotated-secret')
        ->call('saveSlack')
        ->assertHasErrors('slackCurrentSigningSecret');

    expect(ChatIntegration::where('team_id', $team->id)->where('provider', 'slack')->first()->signing_secret)
        ->toBe(SLACK_SECRET);
});

test('an admin holding the current slack signing secret rotates it and the rotation is audited', function () {
    [$team] = chatSetup();
    $admin = User::factory()->create(['is_admin' => true]);
    session(['current_team_id' => $team->id]);

    Livewire\Livewire::actingAs($admin)
        ->test(ChatOps::class)
        ->set('slackCurrentSigningSecret', SLACK_SECRET)
        ->set('slackSigningSecret', 'rotated-secret')
        ->call('saveSlack')
        ->assertHasNoErrors();

    $rotation = AuditLog::where('action', 'chat_integration.secret_rotated')->first();

    expect(ChatIntegration::where('team_id', $team->id)->where('provider', 'slack')->first()->signing_secret)
        ->toBe('rotated-secret')
        ->and($rotation)->not->toBeNull()
        ->and($rotation->user_id)->toBe($admin->id)
        ->and($rotation->metadata)->toBe(['provider' => 'slack', 'initial_setup' => false])
        ->and(json_encode($rotation->metadata))->not->toContain('rotated-secret');
});

test('a failed chat notification never writes the webhook url to the log', function () {
    Notification::fake();

    Http::fake(function ($pending) {
        throw new ConnectionException("cURL error 6: Could not resolve host for {$pending->url()}");
    });

    Log::spy();

    [, , , $request] = chatSetup();

    app(ChatNotifier::class)->requestSubmitted($request);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) {
            return $message === 'QueryProxy: chat notification failed'
                && str_contains($context['error'], '[redacted-webhook]')
                && ! str_contains($context['error'], 'hooks.slack.com')
                && ! str_contains($context['error'], 'outlook.office.com');
        })
        ->twice();
});
