<?php

use App\Http\Controllers\Integrations\SlackInstallController;
use App\Jobs\SuggestSlackIdentities;
use App\Models\AuditLog;
use App\Models\ChatIntegration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SlackApp;

function slackInstaller(string $role = 'dba'): array
{
    SlackApp::configure();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => $role]);

    return [$team, $user];
}

/** Start an install as the user and return the state Slack would send back. */
function startSlackInstall(object $test, User $user): string
{
    $response = $test->actingAs($user)->get(route('integrations.slack.install'));
    $response->assertRedirect();

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

function slackOAuthAnswer(array $overrides = []): array
{
    return [
        'ok' => true,
        'access_token' => 'xoxb-brand-new-token',
        'token_type' => 'bot',
        'scope' => 'chat:write,commands',
        'bot_user_id' => 'U_NEWBOT',
        'team' => ['id' => 'T_NEW', 'name' => 'Acme Inc'],
        'incoming_webhook' => ['channel_id' => 'C_PICKED', 'url' => 'https://hooks.slack.com/services/T/B/SECRET'],
        ...$overrides,
    ];
}

test('the install redirects to Slack with the scopes, the callback URL and a state kept in the session', function () {
    [$team, $user] = slackInstaller();

    $response = $this->actingAs($user)->get(route('integrations.slack.install'));

    $location = $response->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://slack.com/oauth/v2/authorize?')
        ->and($query['client_id'])->toBe('client-id')
        ->and($query['redirect_uri'])->toBe(route('integrations.slack.callback'))
        ->and(explode(',', $query['scope']))->toContain('chat:write', 'commands', 'incoming-webhook')
        ->and(strlen($query['state']))->toBe(40)
        ->and(session(SlackInstallController::SESSION_KEY)['state'])->toBe($query['state'])
        ->and(session(SlackInstallController::SESSION_KEY)['team_id'])->toBe($team->id);
});

test('the install is not available until the app is configured', function () {
    [, $user] = slackInstaller();
    config(['services.slack.app.client_id' => null]);

    $this->actingAs($user)->get(route('integrations.slack.install'))->assertNotFound();
});

test('a developer cannot start an install', function () {
    [, $user] = slackInstaller('developer');

    $this->actingAs($user)->get(route('integrations.slack.install'))->assertForbidden();
});

test('a valid callback stores the encrypted bot token per team and audits the install without it', function () {
    Queue::fake();
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackOAuthAnswer())]);

    [$team, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['code' => 'the-code', 'state' => $state]))
        ->assertRedirect(route('settings.chatops'));

    $integration = ChatIntegration::where('team_id', $team->id)->sole();

    expect($integration->bot_token)->toBe('xoxb-brand-new-token')
        ->and($integration->slack_team_id)->toBe('T_NEW')
        ->and($integration->slack_channel_id)->toBe('C_PICKED')
        ->and($integration->bot_user_id)->toBe('U_NEWBOT')
        ->and($integration->installed_by)->toBe($user->id)
        ->and($integration->enabled)->toBeTrue()
        // The incoming webhook URL Slack hands out is not kept.
        ->and($integration->webhook_url)->toBeNull();

    // Encrypted at rest.
    $raw = DB::table('chat_integrations')->where('id', $integration->id)->value('bot_token');
    expect($raw)->not->toContain('xoxb-brand-new-token');

    $log = AuditLog::where('action', 'slack.installed')->sole();

    expect($log->user_id)->toBe($user->id)
        ->and($log->team_id)->toBe($team->id)
        ->and($log->metadata['slack_team_id'])->toBe('T_NEW')
        ->and($log->metadata['scopes'])->toBe(['chat:write', 'commands'])
        ->and(json_encode($log->metadata))->not->toContain('xoxb-')
        ->and(json_encode($log->metadata))->not->toContain('SECRET');

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'oauth.v2.access')
        && $sent['code'] === 'the-code'
        && $sent['redirect_uri'] === route('integrations.slack.callback')
        && $sent->hasHeader('Authorization'));

    Queue::assertPushed(SuggestSlackIdentities::class, fn ($job) => $job->chatIntegrationId === $integration->id);
});

test('an install keeps the webhook URL and signing secret already configured', function () {
    Queue::fake();
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackOAuthAnswer())]);

    [$team, $user] = slackInstaller();
    ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/OLD',
        'signing_secret' => 'old-secret', 'enabled' => false,
    ]);

    $state = startSlackInstall($this, $user);
    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]));

    $integration = ChatIntegration::where('team_id', $team->id)->sole();

    expect($integration->webhook_url)->toBe('https://hooks.slack.com/services/T/B/OLD')
        ->and($integration->signing_secret)->toBe('old-secret')
        ->and($integration->bot_token)->toBe('xoxb-brand-new-token')
        ->and($integration->enabled)->toBeTrue()
        ->and(AuditLog::where('action', 'slack.installed')->sole()->metadata['reinstall'])->toBeFalse();
});

test('a callback with a wrong state is refused and nothing is exchanged', function () {
    Http::fake();

    [$team, $user] = slackInstaller();
    startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => str_repeat('a', 40)]))
        ->assertForbidden();

    Http::assertNothingSent();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'slack.installed')->exists())->toBeFalse();
});

test('a callback without a state, or without a started install, is refused', function () {
    Http::fake();

    [, $user] = slackInstaller();

    $this->actingAs($user)->get(route('integrations.slack.callback', ['code' => 'c']))->assertForbidden();
    $this->actingAs($user)->get(route('integrations.slack.callback', ['code' => 'c', 'state' => 'forged']))->assertForbidden();

    Http::assertNothingSent();
});

test('the state works once', function () {
    Queue::fake();
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackOAuthAnswer())]);

    [, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertRedirect();
    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();

    Http::assertSentCount(1);
});

test('a wrong state also spends the stored one', function () {
    Http::fake();

    [, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => 'wrong']))->assertForbidden();
    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();

    Http::assertNothingSent();
});

test('an expired state is refused', function () {
    Http::fake();

    [, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->travel(SlackInstallController::STATE_LIFETIME_MINUTES + 1)->minutes();

    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();

    Http::assertNothingSent();
});

test('a state started by one user cannot be completed by another session', function () {
    Http::fake();

    [$team, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'dba']);

    // The other user's session holds no state of their own.
    $this->actingAs($other)->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();

    expect(ChatIntegration::count())->toBe(0);
});

test('a role lost during the round trip stops the install', function () {
    Http::fake();

    [$team, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $team->users()->updateExistingPivot($user->id, ['role' => 'developer']);

    // A new request loads the user again, as the application does.
    $this->actingAs($user->fresh())->get(route('integrations.slack.callback', ['code' => 'c', 'state' => $state]))->assertForbidden();

    Http::assertNothingSent();
});

test('a cancelled install changes nothing', function () {
    Http::fake();

    [$team, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['error' => 'access_denied', 'state' => $state]))
        ->assertRedirect(route('settings.chatops'))
        ->assertSessionHas('slack_install_error');

    Http::assertNothingSent();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse();
});

test('Slack refusing the code changes nothing and shows the error code', function () {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_code'])]);

    [$team, $user] = slackInstaller();
    $state = startSlackInstall($this, $user);

    $this->get(route('integrations.slack.callback', ['code' => 'bad', 'state' => $state]))
        ->assertRedirect(route('settings.chatops'))
        ->assertSessionHas('slack_install_error', fn (string $message) => str_contains($message, 'invalid_code'));

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'slack.installed')->exists())->toBeFalse();
});

test('the callback needs a signed-in user', function () {
    $this->get(route('integrations.slack.callback', ['code' => 'c', 'state' => 'x']))->assertRedirect(route('login'));
});
