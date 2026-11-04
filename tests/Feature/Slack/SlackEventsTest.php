<?php

use App\Models\AuditLog;
use App\Models\ChatIntegration;
use App\Models\Team;
use Tests\Support\SlackApp;

function slackEvent(array $event, array $envelope = []): array
{
    return [
        'type' => 'event_callback',
        'team_id' => SlackApp::WORKSPACE,
        'event' => $event,
        ...$envelope,
    ];
}

test('the url verification challenge is answered', function () {
    SlackApp::configure();

    SlackApp::postJson($this, route('webhooks.slack.events'), ['type' => 'url_verification', 'challenge' => 'abc123'])
        ->assertOk()
        ->assertJson(['challenge' => 'abc123']);
});

test('app_uninstalled deletes the bot token and audits it', function () {
    [$team, , , $integration] = SlackApp::team(['webhook_url' => 'https://hooks.slack.com/services/T/B/KEEP', 'signing_secret' => 'legacy']);

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent(['type' => 'app_uninstalled']))->assertOk();

    $integration->refresh();
    $log = AuditLog::where('action', 'slack.uninstalled')->sole();

    expect($integration->bot_token)->toBeNull()
        ->and($integration->slack_team_id)->toBeNull()
        ->and($integration->slack_channel_id)->toBeNull()
        ->and($integration->usesSlackBot())->toBeFalse()
        // The webhook mode, if it was set up, goes on.
        ->and($integration->webhook_url)->toBe('https://hooks.slack.com/services/T/B/KEEP')
        ->and($log->team_id)->toBe($team->id)
        ->and($log->metadata['reason'])->toBe('app_uninstalled')
        ->and($log->metadata['actor'])->toBe('system');
});

test('app_uninstalled ends the installation of every team in that workspace only', function () {
    [, , , $first] = SlackApp::team();
    [, , , $second] = SlackApp::team([], '_2');
    [, , , $elsewhere] = SlackApp::team(['slack_team_id' => 'T_ELSEWHERE'], '_3');

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent(['type' => 'app_uninstalled']))->assertOk();

    expect($first->fresh()->bot_token)->toBeNull()
        ->and($second->fresh()->bot_token)->toBeNull()
        ->and($elsewhere->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN)
        ->and(AuditLog::where('action', 'slack.uninstalled')->count())->toBe(2);
});

test('tokens_revoked for the bot deletes the token', function () {
    [, , , $integration] = SlackApp::team();

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent([
        'type' => 'tokens_revoked',
        'tokens' => ['oauth' => [], 'bot' => ['U_BOT']],
    ]))->assertOk();

    expect($integration->fresh()->bot_token)->toBeNull()
        ->and(AuditLog::where('action', 'slack.uninstalled')->sole()->metadata['reason'])->toBe('tokens_revoked');
});

test('tokens_revoked for user tokens only leaves the installation alone', function () {
    [, , , $integration] = SlackApp::team();

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent([
        'type' => 'tokens_revoked',
        'tokens' => ['oauth' => ['U_SOMEONE'], 'bot' => []],
    ]))->assertOk();

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent([
        'type' => 'tokens_revoked',
        'tokens' => ['oauth' => [], 'bot' => ['U_ANOTHER_BOT']],
    ]))->assertOk();

    expect($integration->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN)
        ->and(AuditLog::where('action', 'slack.uninstalled')->exists())->toBeFalse();
});

test('other events are acknowledged and ignored', function () {
    [, , , $integration] = SlackApp::team();

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent(['type' => 'message']))->assertOk();

    expect($integration->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN);
});

test('an unsigned or wrongly signed event is refused and changes nothing', function () {
    [, , , $integration] = SlackApp::team();
    $payload = slackEvent(['type' => 'app_uninstalled']);

    $this->postJson(route('webhooks.slack.events'), $payload)->assertUnauthorized();
    SlackApp::postJson($this, route('webhooks.slack.events'), $payload, 'not-the-secret')->assertUnauthorized();
    SlackApp::postJson($this, route('webhooks.slack.events'), $payload, null, now()->getTimestamp() - 600)->assertUnauthorized();

    expect($integration->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN);
});

test('a team signing secret cannot sign events', function () {
    [$team] = SlackApp::team();
    $other = Team::factory()->create();
    ChatIntegration::create([
        'team_id' => $other->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'signing_secret' => 'team-own-secret', 'enabled' => true,
    ]);

    SlackApp::postJson($this, route('webhooks.slack.events'), slackEvent(['type' => 'app_uninstalled']), 'team-own-secret')
        ->assertUnauthorized();

    expect(ChatIntegration::where('team_id', $team->id)->sole()->bot_token)->toBe(SlackApp::BOT_TOKEN);
});

test('the events endpoint is closed while the app has no signing secret', function () {
    config(['services.slack.app.signing_secret' => null]);

    SlackApp::postJson($this, route('webhooks.slack.events'), ['type' => 'url_verification', 'challenge' => 'x'], '')
        ->assertUnauthorized();
});
