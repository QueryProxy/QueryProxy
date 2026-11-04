<?php

use App\Enums\QueryRequestStatus;
use App\Http\Middleware\VerifySlackSignature;
use App\Models\ChatApprovalToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\SlackApp;

const SHARED_SECRET = 'one-secret-for-app-and-team';

/** The Slack app and the team share one signing secret. Set after the team is built: SlackApp::team() configures the app. */
function shareSigningSecret(): void
{
    config(['services.slack.app.signing_secret' => SHARED_SECRET]);
}

function clickPayload(int|string $requestId, string $token, string $workspace = SlackApp::WORKSPACE): array
{
    return ['payload' => json_encode([
        'type' => 'block_actions',
        'team' => ['id' => $workspace],
        'user' => ['id' => 'U_DBA'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$requestId}:{$token}"]],
    ])];
}

test('a webhook-mode team keeps working when its signing secret is the app signing secret', function () {
    Queue::fake();

    // No installation: only the incoming webhook and the secret of the Slack
    // app the operator has since turned into the QueryProxy app.
    [$team, , $developer, $integration] = SlackApp::team([
        'bot_token' => null, 'bot_user_id' => null, 'slack_team_id' => null, 'slack_channel_id' => null,
        'webhook_url' => 'https://hooks.slack.com/services/T/B/X', 'signing_secret' => SHARED_SECRET,
    ]);
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);
    $token = ChatApprovalToken::issueFor($request);
    shareSigningSecret();

    $response = SlackApp::postForm($this, route('webhooks.slack'), clickPayload($request->id, $token), SHARED_SECRET);

    $response->assertOk();

    expect($response->json('replace_original'))->toBeTrue()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->fresh()->review_channel)->toBe('slack');
});

test('the shared secret does not let a click decide a request of a team it is not configured for', function () {
    Queue::fake();

    [$webhookTeam] = SlackApp::team([
        'bot_token' => null, 'slack_team_id' => null, 'slack_channel_id' => null,
        'webhook_url' => 'https://hooks.slack.com/services/T/B/X', 'signing_secret' => SHARED_SECRET,
    ]);

    // Another team without any Slack integration.
    $other = Team::factory()->create();
    [, , $developer] = [null, null, User::factory()->create()];
    $other->users()->attach($developer, ['role' => 'developer']);
    $request = SlackApp::request($other, $developer, ['slack_channel' => null, 'slack_ts' => null]);
    shareSigningSecret();

    $response = SlackApp::postForm($this, route('webhooks.slack'), clickPayload($request->id, ChatApprovalToken::issueFor($request)), SHARED_SECRET);

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a team bound to a workspace gets no webhook fallback from another workspace', function () {
    Queue::fake();

    [$team, , $developer] = SlackApp::team(['signing_secret' => SHARED_SECRET, 'webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);
    shareSigningSecret();

    $response = SlackApp::postForm($this, route('webhooks.slack'), clickPayload($request->id, ChatApprovalToken::issueFor($request), 'T_OTHER'), SHARED_SECRET);

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('an app-signed click answers the same for a missing request and for one it may not touch', function () {
    SlackApp::configure();

    [$team, , $developer] = SlackApp::team();
    $other = Team::factory()->create();
    $foreign = SlackApp::request($other, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    $missing = SlackApp::postForm($this, route('webhooks.slack'), clickPayload(999999, 'abcdef'));
    $existing = SlackApp::postForm($this, route('webhooks.slack'), clickPayload($foreign->id, 'abcdef'));

    expect($missing->json())->toBe($existing->json())
        ->and($missing->json('text'))->not->toContain('no longer exists');
});

test('the Slack endpoints use an address flood guard, not the shared 60 per minute bucket', function () {
    $route = app('router')->getRoutes()->getByName('webhooks.slack');
    $order = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

    expect($order)->toContain('Illuminate\\Routing\\Middleware\\ThrottleRequests:slack-ip')
        ->not->toContain('Illuminate\\Routing\\Middleware\\ThrottleRequests:60,1');
});

test('the address flood guard allows 120 requests a minute and the workspace budget 300', function () {
    $guard = RateLimiter::limiter('slack-ip')(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

    expect($guard->maxAttempts)->toBe(120)
        ->and($guard->key)->toContain('203.0.113.7')
        ->and(config('queryproxy.slack_rate_limit'))->toBe(300);
});

test('the address flood guard answers 429 before the signature is checked', function () {
    RateLimiter::for('slack-ip', fn (Request $request) => Limit::perMinute(2)->by('slack-ip:'.$request->ip()));

    $unsigned = fn () => $this->post(route('webhooks.slack.commands'), ['team_id' => 'T_A']);

    $unsigned()->assertUnauthorized();
    $unsigned()->assertUnauthorized();
    $unsigned()->assertStatus(429);
});

test('Teams stays on the 60 per minute bucket', function () {
    $route = app('router')->getRoutes()->getByName('webhooks.teams');
    $order = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

    expect($order)->toContain('Illuminate\\Routing\\Middleware\\ThrottleRequests:60,1');
});

test('one busy workspace is throttled without throttling another', function () {
    SlackApp::configure();
    config(['queryproxy.slack_rate_limit' => 2]);

    $command = fn (string $workspace) => SlackApp::postForm($this, route('webhooks.slack.commands'), ['command' => '/queryproxy', 'team_id' => $workspace, 'user_id' => 'U', 'trigger_id' => 't']);

    $command('T_BUSY')->assertOk();
    $command('T_BUSY')->assertOk();
    $command('T_BUSY')->assertStatus(429)->assertHeader('Retry-After');
    $command('T_QUIET')->assertOk();
});

test('the budget of interactions is per workspace too', function () {
    SlackApp::configure();
    config(['queryproxy.slack_rate_limit' => 1]);

    $click = fn (string $workspace) => SlackApp::postForm($this, route('webhooks.slack'), clickPayload(1, 'abc', $workspace));

    $click('T_BUSY')->assertOk();
    $click('T_BUSY')->assertStatus(429);
    $click('T_QUIET')->assertOk();
});

test('requests with a bad signature do not use up a workspace budget', function () {
    SlackApp::configure();
    config(['queryproxy.slack_rate_limit' => 2]);

    for ($i = 0; $i < 5; $i++) {
        SlackApp::postForm($this, route('webhooks.slack.commands'), ['command' => '/queryproxy', 'team_id' => 'T_VICTIM'], 'wrong')->assertUnauthorized();
    }

    SlackApp::postForm($this, route('webhooks.slack.commands'), ['command' => '/queryproxy', 'team_id' => 'T_VICTIM', 'user_id' => 'U', 'trigger_id' => 't'])->assertOk();
});

test('the workspace is read from commands, events and interactions alike', function () {
    $of = fn (array $input) => VerifySlackSignature::workspaceId(Request::create('/', 'POST', $input));

    expect($of(['team_id' => 'T_A']))->toBe('T_A')
        ->and($of(['payload' => json_encode(['team' => ['id' => 'T_A']])]))->toBe('T_A')
        ->and($of(['unrelated' => 1]))->toBeNull();
});
