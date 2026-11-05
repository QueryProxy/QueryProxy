<?php

use App\Livewire\Admin\Users;
use App\Livewire\Settings\ChatOps;
use App\Models\AuditLog;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\SlackIdentitySuggestion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\SlackApp;

/** A team with the Slack app installed, its DBA, a developer and an admin; the session is on that team. */
function uiSlackSetup(array $integration = []): array
{
    [$team, $dba, $developer, $chat] = SlackApp::team($integration);
    $admin = User::factory()->create(['is_admin' => true]);
    session(['current_team_id' => $team->id]);

    return [$team, $dba, $developer, $admin, $chat];
}

function uiSuggestion($team, User $user, string $slackUserId = 'U_SUGGESTED', array $attributes = []): SlackIdentitySuggestion
{
    return SlackIdentitySuggestion::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'slack_team_id' => SlackApp::WORKSPACE,
        'slack_user_id' => $slackUserId,
        'matched_email' => $user->email,
        'status' => 'pending',
        ...$attributes,
    ]);
}

// --- ChatOps settings: the Slack app section ---------------------------------------------------

test('a dba sees the Add to Slack button while the app is not installed', function () {
    SlackApp::configure();
    [$team, $dba] = SlackApp::team();
    ChatIntegration::where('team_id', $team->id)->delete();
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSee('Add to Slack')
        ->assertSee(route('integrations.slack.install'), false)
        ->assertDontSee('Remove app');
});

test('the page says so when the server has no Slack app configured, and offers no install', function () {
    [$team, $dba] = SlackApp::team();
    ChatIntegration::where('team_id', $team->id)->delete();
    config(['services.slack.app.client_id' => null]);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSee('QUERYPROXY_SLACK_CLIENT_ID')
        ->assertDontSee('Add to Slack')
        ->assertDontSee(route('integrations.slack.install'), false);
});

test('an installed app shows the workspace, the channel, who installed it and when', function () {
    [$team, $dba, , , $chat] = uiSlackSetup(['installed_by' => null]);
    $chat->update(['installed_by' => $dba->id, 'installed_at' => '2026-11-03 09:30:00']);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSee('Installed')
        ->assertSee('Acme')
        ->assertSee(SlackApp::WORKSPACE)
        ->assertSee(SlackApp::CHANNEL)
        ->assertSee($dba->name)
        ->assertSee('2026-11-03 09:30')
        ->assertSee('Add to Slack again')
        ->assertSee('Remove app')
        // The token never reaches the page.
        ->assertDontSee(SlackApp::BOT_TOKEN);
});

test('an install without a channel warns that the webhook still carries the announcements', function () {
    [, $dba] = uiSlackSetup(['slack_channel_id' => null]);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSee('No channel was chosen');
});

test('the error of a failed install is shown on the page', function () {
    SlackApp::configure();
    [$team, $dba] = SlackApp::team();
    session(['current_team_id' => $team->id]);

    $this->actingAs($dba)->withSession(['slack_install_error' => 'Slack refused the install (invalid_code).'])
        ->get(route('settings.chatops'))
        ->assertOk()
        ->assertSee('Slack refused the install (invalid_code).');
});

test('a developer can neither open the page nor install, uninstall or change the DM switch', function () {
    [$team, , $developer, , $chat] = uiSlackSetup();

    $this->actingAs($developer)->get(route('settings.chatops'))->assertForbidden();
    $this->actingAs($developer)->get(route('integrations.slack.install'))->assertForbidden();

    Livewire::actingAs($developer)->test(ChatOps::class)->call('uninstallSlack')->assertForbidden();
    Livewire::actingAs($developer)->test(ChatOps::class)->set('slackDmApprovers', true)->assertForbidden();

    $chat->refresh();

    expect($chat->bot_token)->toBe(SlackApp::BOT_TOKEN)
        ->and($chat->dm_approvers)->toBeFalse()
        ->and(AuditLog::where('action', 'slack.uninstalled')->exists())->toBeFalse();
});

test('a dba of another team cannot touch this team\'s installation', function () {
    [$team, , , , $chat] = uiSlackSetup();
    [$otherTeam, $otherDba] = SlackApp::team([], '_OTHER');
    ChatIntegration::where('team_id', $otherTeam->id)->delete();
    session(['current_team_id' => $otherTeam->id]);

    // Their own team has no installation: nothing to remove, and ours stays.
    Livewire::actingAs($otherDba)->test(ChatOps::class)
        ->call('uninstallSlack')
        ->assertHasErrors('slackApp');

    expect($chat->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN);
});

test('a dba removes the app: the token is deleted, the shell row goes and the removal is audited', function () {
    [$team, $dba, $developer] = uiSlackSetup();
    SlackApp::fakeApi();
    uiSuggestion($team, $developer);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->call('uninstallSlack')
        ->assertHasNoErrors();

    $log = AuditLog::where('action', 'slack.uninstalled')->sole();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse()
        ->and(SlackIdentitySuggestion::count())->toBe(0)
        ->and($log->user_id)->toBe($dba->id)
        ->and($log->team_id)->toBe($team->id)
        ->and($log->metadata)->toBe(['slack_team_id' => SlackApp::WORKSPACE, 'reason' => 'removed_by_user', 'token_revoked' => true])
        ->and(json_encode($log->metadata))->not->toContain('xoxb-');
});

test('removing the app revokes the bot token on Slack with that very token', function () {
    [$team, $dba] = uiSlackSetup();
    SlackApp::fakeApi();

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth.revoke')
        && $request->hasHeader('Authorization', 'Bearer '.SlackApp::BOT_TOKEN));
});

test('removing the app clears the token and who/when installed it', function () {
    [$team, $dba, , , $chat] = uiSlackSetup([
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'team-secret',
        'installed_by' => null,
        'installed_at' => now(),
    ]);
    $chat->forceFill(['installed_by' => $dba->id])->save();
    SlackApp::fakeApi();

    expect($chat->fresh()->installed_by)->toBe($dba->id)->and($chat->fresh()->installed_at)->not->toBeNull();

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    $chat->refresh();

    expect($chat->bot_token)->toBeNull()
        ->and($chat->installed_by)->toBeNull()
        ->and($chat->installed_at)->toBeNull();
});

test('a Slack failure on revoke does not keep the installation locally', function (string $failure, string $expectedError) {
    [$team, $dba, , , $chat] = uiSlackSetup([
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'team-secret',
        'installed_at' => now(),
    ]);
    Http::fake(['slack.com/api/auth.revoke' => match ($failure) {
        'server_error' => Http::response('boom', 500),
        'not_ok' => Http::response(['ok' => false, 'error' => 'invalid_auth']),
        'connection' => Http::failedConnection(),
    }]);
    Log::spy();

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    $chat->refresh();
    $log = AuditLog::where('action', 'slack.uninstalled')->sole();

    expect($chat->bot_token)->toBeNull()
        ->and($chat->slack_team_id)->toBeNull()
        ->and($chat->installed_at)->toBeNull()
        ->and($log->metadata)->toBe([
            'slack_team_id' => SlackApp::WORKSPACE,
            'reason' => 'removed_by_user',
            'token_revoked' => false,
            'revoke_error' => $expectedError,
        ])
        ->and(json_encode($log->metadata))->not->toContain('xoxb-');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'auth.revoke')
        && $context['error'] === $expectedError
        && ! str_contains(json_encode($context), 'xoxb-'));
})->with([
    'slack 500' => ['server_error', 'http_500'],
    'ok false' => ['not_ok', 'invalid_auth'],
    'connection error' => ['connection', 'connection_failed'],
]);

test('a failed revoke on a row without a webhook still deletes the shell row', function () {
    [$team, $dba] = uiSlackSetup();
    Http::fake(['slack.com/api/auth.revoke' => Http::response('boom', 500)]);

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse();
});

test('a tokens_revoked event arriving after a removal finds nothing and writes no second audit', function () {
    [$team, $dba] = uiSlackSetup(['webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX', 'signing_secret' => 'team-secret']);
    SlackApp::fakeApi();

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    SlackApp::postJson($this, route('webhooks.slack.events'), [
        'type' => 'event_callback',
        'team_id' => SlackApp::WORKSPACE,
        'event' => ['type' => 'tokens_revoked', 'tokens' => ['oauth' => [], 'bot' => ['U_BOT']]],
    ])->assertOk();

    expect(AuditLog::where('action', 'slack.uninstalled')->count())->toBe(1)
        ->and(AuditLog::where('action', 'slack.uninstalled')->sole()->metadata['reason'])->toBe('removed_by_user');
});

test('the events handler re-reads the row under lock and skips an installation that changed after the first query', function (array $change, bool $tokenSurvives) {
    [, , , $integration] = SlackApp::team();

    // Change the row right after the handler's first query loaded it, as a
    // concurrent removal or re-install would: only the locked re-read sees it.
    $changed = false;
    ChatIntegration::retrieved(function (ChatIntegration $loaded) use (&$changed, $change) {
        if (! $changed) {
            $changed = true;
            DB::table('chat_integrations')->where('id', $loaded->id)->update($change);
        }
    });

    try {
        SlackApp::postJson($this, route('webhooks.slack.events'), [
            'type' => 'event_callback',
            'team_id' => SlackApp::WORKSPACE,
            'event' => ['type' => 'tokens_revoked', 'tokens' => ['oauth' => [], 'bot' => ['U_BOT']]],
        ])->assertOk();
    } finally {
        ChatIntegration::flushEventListeners();
    }

    expect($changed)->toBeTrue()
        ->and(AuditLog::where('action', 'slack.uninstalled')->exists())->toBeFalse()
        ->and(filled($integration->fresh()->bot_token))->toBe($tokenSurvives);
})->with([
    'already ended by someone else' => [['slack_team_id' => null, 'bot_token' => null], false],
    'now a different bot user' => [['bot_user_id' => 'U_REINSTALLED'], true],
]);

test('removing the app keeps a webhook that was set up beside it', function () {
    [$team, $dba] = uiSlackSetup([
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'team-secret',
        'dm_approvers' => true,
    ]);
    SlackApp::fakeApi();

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    $chat = ChatIntegration::where('team_id', $team->id)->sole();

    expect($chat->bot_token)->toBeNull()
        ->and($chat->slack_team_id)->toBeNull()
        ->and($chat->slack_channel_id)->toBeNull()
        ->and($chat->dm_approvers)->toBeFalse()
        ->and($chat->webhook_url)->toBe('https://hooks.slack.com/services/T000/B000/XXX')
        ->and($chat->signing_secret)->toBe('team-secret')
        ->and($chat->usesSlackBot())->toBeFalse();
});

test('removing the app drops pending suggestions but keeps the dismissed ones', function () {
    [$team, $dba, $developer, $admin] = uiSlackSetup();
    SlackApp::fakeApi();
    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'developer']);
    $pending = uiSuggestion($team, $developer);
    $dismissed = uiSuggestion($team, $other, 'U_REJECTED', ['dismissed_at' => now()]);

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack')->assertHasNoErrors();

    expect(SlackIdentitySuggestion::find($pending->id))->toBeNull()
        ->and(SlackIdentitySuggestion::find($dismissed->id))->not->toBeNull();
});

test('suggestions of another team in the same workspace survive a removal', function () {
    [$team, $dba, $developer] = uiSlackSetup();
    SlackApp::fakeApi();
    [$otherTeam, , $otherDeveloper] = SlackApp::team([], '_OTHER');
    uiSuggestion($team, $developer);
    $kept = uiSuggestion($otherTeam, $otherDeveloper, 'U_OTHER');

    Livewire::actingAs($dba)->test(ChatOps::class)->call('uninstallSlack');

    expect(SlackIdentitySuggestion::sole()->id)->toBe($kept->id);
});

test('the DM switch saves itself, is audited and only exists with the app installed', function () {
    [$team, $dba, , , $chat] = uiSlackSetup();

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSet('slackDmApprovers', false)
        ->set('slackDmApprovers', true)
        ->assertHasNoErrors();

    expect($chat->fresh()->dm_approvers)->toBeTrue();

    $log = AuditLog::where('action', 'chat_integration.saved')->latest('id')->first();
    expect($log->metadata)->toMatchArray(['provider' => 'slack', 'dm_approvers' => true]);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSet('slackDmApprovers', true)
        ->set('slackDmApprovers', false);

    expect($chat->fresh()->dm_approvers)->toBeFalse();

    // Without an installation there is nothing to switch.
    ChatIntegration::where('team_id', $team->id)->delete();

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->set('slackDmApprovers', true)
        ->assertHasErrors('slackApp')
        ->assertSet('slackDmApprovers', false);
});

test('the DM switch is disabled and refused while the install has no channel', function () {
    [, $dba, , , $chat] = uiSlackSetup(['slack_channel_id' => null]);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->assertSeeHtml('disabled')
        ->assertSee('Needs a channel.')
        ->assertSee('direct messages to approvers cannot be sent')
        ->set('slackDmApprovers', true)
        ->assertHasErrors('slackApp')
        ->assertSet('slackDmApprovers', false);

    expect($chat->fresh()->dm_approvers)->toBeFalse()
        ->and(AuditLog::where('action', 'chat_integration.saved')->exists())->toBeFalse();

    // With a channel the control is live and not disabled.
    $chat->update(['slack_channel_id' => SlackApp::CHANNEL]);

    $html = Livewire::actingAs($dba)->test(ChatOps::class)->html();

    expect($html)->not->toContain('Needs a channel.')
        ->and($html)->not->toMatch('/<input[^>]*slackDmApprovers[^>]*disabled/');
});

test('switching the DM switch tells the user it was saved', function () {
    [, $dba] = uiSlackSetup();

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->set('slackDmApprovers', true)
        ->assertSee('Direct messages to approvers switched on.')
        ->assertSeeHtml('role="status"');
});

test('an admin removing the whole integration also records the app uninstall', function () {
    [$team, , , $admin] = uiSlackSetup();
    SlackApp::fakeApi();

    Livewire::actingAs($admin)->test(ChatOps::class)->call('remove', 'slack')->assertHasNoErrors();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth.revoke')
        && $request->hasHeader('Authorization', 'Bearer '.SlackApp::BOT_TOKEN));

    $uninstall = AuditLog::where('action', 'slack.uninstalled')->sole();
    $removal = AuditLog::where('action', 'chat_integration.removed')->sole();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse()
        ->and($uninstall->user_id)->toBe($admin->id)
        ->and($removal->metadata)->toBe(['provider' => 'slack', 'existed' => true]);
});

// --- Webhook mode beside the app ---------------------------------------------------------------

test('an app-only row lets a dba switch the integration off without a webhook or a secret', function () {
    [$team, $dba, , , $chat] = uiSlackSetup();

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->set('slackEnabled', false)
        ->call('saveSlack')
        ->assertHasNoErrors();

    expect($chat->fresh()->enabled)->toBeFalse();
});

test('a webhook cannot be added to an app-only row without a signing secret', function () {
    [$team, $dba, , $admin, $chat] = uiSlackSetup();

    // A DBA cannot set the secret, so the webhook has to wait for an admin.
    $component = Livewire::actingAs($dba)->test(ChatOps::class)
        ->set('slackWebhookUrl', 'https://hooks.slack.com/services/T111/B111/YYY')
        ->call('saveSlack')
        ->assertHasErrors('slackWebhookUrl');

    expect($component->errors()->first('slackWebhookUrl'))->toContain('admin')
        ->and($chat->fresh()->webhook_url)->toBeNull();

    Livewire::actingAs($admin)->test(ChatOps::class)
        ->set('slackWebhookUrl', 'https://hooks.slack.com/services/T111/B111/YYY')
        ->call('saveSlack')
        ->assertHasErrors('slackSigningSecret');

    expect($chat->fresh()->webhook_url)->toBeNull();
});

test('an admin sets the first signing secret on an app-only row without a current one', function () {
    [$team, , , $admin, $chat] = uiSlackSetup();

    Livewire::actingAs($admin)->test(ChatOps::class)
        ->assertDontSee('Current signing secret')
        ->set('slackWebhookUrl', 'https://hooks.slack.com/services/T111/B111/YYY')
        ->set('slackSigningSecret', 'first-secret')
        ->call('saveSlack')
        ->assertHasNoErrors();

    $chat->refresh();

    expect($chat->webhook_url)->toBe('https://hooks.slack.com/services/T111/B111/YYY')
        ->and($chat->signing_secret)->toBe('first-secret')
        // The installation is untouched.
        ->and($chat->bot_token)->toBe(SlackApp::BOT_TOKEN);

    $rotation = AuditLog::where('action', 'chat_integration.secret_rotated')->sole();
    expect($rotation->metadata)->toBe(['provider' => 'slack', 'initial_setup' => true])
        ->and(json_encode($rotation->metadata))->not->toContain('first-secret');

    // From here on a rotation does need the secret in place.
    Livewire::actingAs($admin)->test(ChatOps::class)
        ->assertSee('Current signing secret')
        ->set('slackSigningSecret', 'second-secret')
        ->call('saveSlack')
        ->assertHasErrors('slackCurrentSigningSecret');

    expect($chat->fresh()->signing_secret)->toBe('first-secret');
});

// --- Admin users: Slack matching suggestions ---------------------------------------------------

test('an admin sees the pending suggestions with user, proposed id and source', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create(['email' => 'new.person@example.com']);
    $team->users()->attach($newcomer, ['role' => 'developer']);
    uiSuggestion($team, $newcomer, 'U_SUGGESTED');

    $this->actingAs($admin)->get(route('admin.users'))
        ->assertOk()
        ->assertSee('Slack matching suggestions')
        ->assertSee('U_SUGGESTED')
        ->assertSee('new.person@example.com')
        ->assertSee('E-mail match')
        ->assertSee('Acme')
        ->assertSee('Approve')
        ->assertSee('Reject');
});

test('the suggestions panel stays out of the way when there is nothing to confirm', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('admin.users'))
        ->assertOk()
        ->assertDontSee('Slack matching suggestions');
});

test('suggestions that can no longer be acted on are not listed', function () {
    [$team, $dba, $developer, $admin] = uiSlackSetup();
    $linked = User::factory()->create();
    $team->users()->attach($linked, ['role' => 'developer']);
    $gone = User::factory()->create();
    $team->users()->attach($gone, ['role' => 'developer']);
    $held = User::factory()->create();
    $team->users()->attach($held, ['role' => 'developer']);

    // dba and developer already have identities; give the rest one reason each to be stale.
    ChatIdentity::create(['user_id' => $linked->id, 'provider' => 'slack', 'external_id' => 'U_LINKED', 'team_id' => SlackApp::WORKSPACE]);
    uiSuggestion($team, $linked, 'U_STALE_LINKED');
    uiSuggestion($team, $gone, 'U_STALE_DELETED');
    $gone->delete();
    uiSuggestion($team, $held, 'U_STALE_TAKEN');
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'noise']);
    ChatIdentity::where('user_id', $developer->id)->update(['external_id' => 'U_STALE_TAKEN']);

    Livewire::actingAs($admin)->test(Users::class)
        // The ids may still show in the table's inputs; the panel is what must be gone.
        ->assertDontSee('Slack matching suggestions');

    // The address the match was made on is gone (SCIM or the IdP changed it).
    $renamed = User::factory()->create(['email' => 'before@example.com']);
    $team->users()->attach($renamed, ['role' => 'developer']);
    uiSuggestion($team, $renamed, 'U_RENAMED');
    $renamed->update(['email' => 'after@example.com']);

    Livewire::actingAs($admin)->test(Users::class)->assertDontSee('Slack matching suggestions');

    // Case alone is not a different address.
    $renamed->update(['email' => 'BEFORE@example.com']);

    Livewire::actingAs($admin)->test(Users::class)->assertSee('Slack matching suggestions');

    $renamed->delete();

    // Once the workspace is disconnected, even a clean suggestion goes quiet.
    $fresh = User::factory()->create();
    $team->users()->attach($fresh, ['role' => 'developer']);
    uiSuggestion($team, $fresh, 'U_FRESH');

    Livewire::actingAs($admin)->test(Users::class)->assertSee('U_FRESH');

    ChatIntegration::where('team_id', $team->id)->update(['bot_token' => null]);

    Livewire::actingAs($admin)->test(Users::class)->assertDontSee('U_FRESH');
});

test('approving a suggestion links the Slack ID through the normal path and audits it', function () {
    [$team, , $developer, $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');
    // A second suggestion for the same user disappears with the first.
    uiSuggestion($team, $newcomer, 'U_OTHER_WORKSPACE', ['slack_team_id' => 'T_ELSEWHERE']);

    Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasNoErrors();

    $identity = ChatIdentity::where('user_id', $newcomer->id)->sole();
    $log = AuditLog::where('action', 'chat_identity.linked')->where('metadata->user', $newcomer->email)->sole();

    expect($identity->provider)->toBe('slack')
        ->and($identity->external_id)->toBe('U_NEWCOMER')
        ->and(SlackIdentitySuggestion::count())->toBe(0)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata)->toMatchArray([
            'user' => $newcomer->email,
            'provider' => 'slack',
            'external_id' => 'U_NEWCOMER',
            'source' => 'slack_suggestion',
            'slack_team_id' => SlackApp::WORKSPACE,
        ]);
});

test('rejecting a suggestion keeps it as dismissed, hides it and links nothing', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    Livewire::actingAs($admin)->test(Users::class)
        ->call('rejectSlackSuggestion', $suggestion->id)
        ->assertHasNoErrors();

    expect(SlackIdentitySuggestion::pending()->count())->toBe(0)
        ->and($suggestion->fresh()->dismissed_at)->not->toBeNull()
        ->and(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'chat_identity.linked')->exists())->toBeFalse();

    Livewire::actingAs($admin)->test(Users::class)
        ->assertDontSee('Slack matching suggestions')
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasErrors('suggestions');

    expect(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse();
});

test('only a system admin can approve or reject', function () {
    [$team, $dba, $developer] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    foreach ([$dba, $developer] as $actor) {
        Livewire::actingAs($actor)->test(Users::class)->call('approveSlackSuggestion', $suggestion->id)->assertForbidden();
        Livewire::actingAs($actor)->test(Users::class)->call('rejectSlackSuggestion', $suggestion->id)->assertForbidden();
    }

    expect(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(SlackIdentitySuggestion::count())->toBe(1);
});

test('a suggested id that now belongs to someone else is refused', function () {
    [$team, , $developer, $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    // U_DEV is already the developer's.
    $suggestion = uiSuggestion($team, $newcomer, 'U_DEV');

    $component = Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasErrors('suggestions');

    expect($component->errors()->first('suggestions'))->toContain('already linked')
        ->and(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(ChatIdentity::where('external_id', 'U_DEV')->sole()->user_id)->toBe($developer->id);
});

test('approving never replaces an identity the user already has', function () {
    [$team, , $developer, $admin] = uiSlackSetup();
    $suggestion = uiSuggestion($team, $developer, 'U_SOMEONE_ELSE');

    Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasErrors('suggestions');

    expect(ChatIdentity::where('user_id', $developer->id)->sole()->external_id)->toBe('U_DEV')
        ->and(SlackIdentitySuggestion::count())->toBe(0);
});

test('a suggestion from a disconnected workspace cannot be approved from a stale page', function () {
    [$team, , , $admin, $chat] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    $chat->clearSlackInstallation();

    Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasErrors('suggestions');

    expect(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(SlackIdentitySuggestion::count())->toBe(0);
});

test('approving a suggestion that was already handled says so instead of failing', function () {
    [, , , $admin] = uiSlackSetup();

    Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', 999999)
        ->assertHasErrors('suggestions');
});

test('typing a Slack ID by hand still works and clears the user\'s suggestions', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    Livewire::actingAs($admin)->test(Users::class)
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasNoErrors();

    $log = AuditLog::where('action', 'chat_identity.linked')->where('metadata->user', $newcomer->email)->sole();

    expect(ChatIdentity::where('user_id', $newcomer->id)->sole()->external_id)->toBe('U_TYPED')
        ->and($log->metadata)->not->toHaveKey('source')
        ->and(SlackIdentitySuggestion::count())->toBe(0);
});

test('a suggestion whose address changed cannot be approved from a stale page', function () {
    [$team, , , $admin] = uiSlackSetup();
    $person = User::factory()->create(['email' => 'before@example.com']);
    $team->users()->attach($person, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $person, 'U_PERSON');

    $person->update(['email' => 'after@example.com']);

    Livewire::actingAs($admin)->test(Users::class)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasErrors('suggestions');

    expect(ChatIdentity::where('user_id', $person->id)->exists())->toBeFalse()
        ->and(SlackIdentitySuggestion::count())->toBe(0);
});

test('the actions column of the suggestions table has an accessible name', function () {
    [$team, , , $admin] = uiSlackSetup();
    $person = User::factory()->create();
    $team->users()->attach($person, ['role' => 'developer']);
    uiSuggestion($team, $person, 'U_PERSON');

    Livewire::actingAs($admin)->test(Users::class)->assertSeeHtml('<span class="sr-only">Actions</span>');
});

// --- Slack identities are bound to a workspace (plan v0.4.2, Faz 03) -------------------------

test('a typed Slack ID is linked in the one workspace the app is installed in', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);

    Livewire::actingAs($admin)->test(Users::class)
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasNoErrors();

    $log = AuditLog::where('action', 'chat_identity.linked')->where('metadata->user', $newcomer->email)->sole();

    expect(ChatIdentity::where('user_id', $newcomer->id)->sole()->team_id)->toBe(SlackApp::WORKSPACE)
        ->and($log->metadata['slack_team_id'])->toBe(SlackApp::WORKSPACE);
});

test('a typed Slack ID takes the workspace entered on the page, which also serves webhook-only setups', function () {
    [$team, , , $admin, $chat] = uiSlackSetup();
    $chat->clearSlackInstallation();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);

    $component = Livewire::actingAs($admin)->test(Users::class)
        // No installation to name the workspace and none entered: nothing is linked.
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasErrors('chat_id');

    expect(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse();

    $component->set('slackWorkspaceId', 'not a workspace')
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasErrors('chat_id');

    $component->set('slackWorkspaceId', 't_elsewhere')
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasNoErrors();

    expect(ChatIdentity::where('user_id', $newcomer->id)->sole()->team_id)->toBe('T_ELSEWHERE');
});

test('with two workspaces installed a typed Slack ID needs the workspace entered', function () {
    [$team, , , $admin] = uiSlackSetup();
    $second = Team::factory()->create();
    SlackApp::install($second, ['slack_team_id' => 'T_SECOND']);
    $newcomer = User::factory()->create();
    // A member of both teams: their own teams name two workspaces.
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $second->users()->attach($newcomer, ['role' => 'developer']);

    Livewire::actingAs($admin)->test(Users::class)
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasErrors('chat_id')
        ->set('slackWorkspaceId', 'T_SECOND')
        ->call('updateSlackId', $newcomer->id, 'U_TYPED')
        ->assertHasNoErrors();

    expect(ChatIdentity::where('user_id', $newcomer->id)->sole()->team_id)->toBe('T_SECOND');
});

test('approving a suggestion binds the identity to the workspace the suggestion came from', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    Livewire::actingAs($admin)->test(Users::class)->call('approveSlackSuggestion', $suggestion->id)->assertHasNoErrors();

    expect(ChatIdentity::where('user_id', $newcomer->id)->sole()->team_id)->toBe(SlackApp::WORKSPACE);
});

test('a suggestion for a user whose identity has no workspace is listed, and approving binds it', function () {
    [$team, , , $admin] = uiSlackSetup();
    $person = User::factory()->create();
    $team->users()->attach($person, ['role' => 'developer']);
    ChatIdentity::create(['user_id' => $person->id, 'provider' => 'slack', 'external_id' => 'U_UNBOUND']);
    $suggestion = uiSuggestion($team, $person, 'U_UNBOUND');

    Livewire::actingAs($admin)->test(Users::class)
        ->assertSee($person->email)
        ->call('approveSlackSuggestion', $suggestion->id)
        ->assertHasNoErrors();

    $identity = ChatIdentity::where('user_id', $person->id)->sole();

    expect($identity->external_id)->toBe('U_UNBOUND')
        ->and($identity->team_id)->toBe(SlackApp::WORKSPACE);
});

test('removing the app keeps the Slack identities, which then match nothing in another workspace', function () {
    // Decision: identities belong to people, not to one team's installation, and a workspace can
    // serve several teams; the workspace binding already keeps them from matching elsewhere.
    [$team, $dba] = uiSlackSetup();

    Http::fake(['slack.com/api/*' => Http::response(['ok' => true])]);
    Livewire::actingAs($dba)->test(ChatOps::class)->call('remove', 'slack');

    expect(ChatIdentity::where('provider', 'slack')->whereNotNull('team_id')->count())->toBe(2);
});

test('a Slack ID without a workspace is marked in the table', function () {
    [, , , $admin] = uiSlackSetup();
    $person = User::factory()->create(['name' => 'Passive Person']);
    ChatIdentity::create(['user_id' => $person->id, 'provider' => 'slack', 'external_id' => 'U_PASSIVE']);

    Livewire::actingAs($admin)->test(Users::class)
        ->assertSee('No workspace')
        ->assertSee('1 Slack ID has no workspace and matches nothing, so that member cannot act from Slack.')
        // No id entered: no bind button, only the request for one.
        ->assertSee('No valid workspace ID entered yet')
        ->assertDontSee('Bind 1 to')
        ->set('slackWorkspaceId', 't0abc12')
        // The button and its confirmation name the id and the number of identities.
        ->assertSee('Bind 1 to T0ABC12')
        ->assertSeeHtml('Bind 1 Slack ID without a workspace to T0ABC12?')
        ->assertSeeHtml('That Slack member will be able to act as their QueryProxy user from T0ABC12.');

    ChatIdentity::where('user_id', $person->id)->update(['team_id' => SlackApp::WORKSPACE]);

    Livewire::actingAs($admin)->test(Users::class)->assertDontSee('No workspace');
});

test('the unbound Slack ID banner uses the plural form for several identities', function () {
    [, , , $admin] = uiSlackSetup();
    foreach (['U_ONE', 'U_TWO'] as $externalId) {
        ChatIdentity::create(['user_id' => User::factory()->create()->id, 'provider' => 'slack', 'external_id' => $externalId]);
    }

    Livewire::actingAs($admin)->test(Users::class)
        ->assertSee('2 Slack IDs have no workspace and match nothing, so those members cannot act from Slack.')
        ->set('slackWorkspaceId', 't0abc12')
        ->assertSeeHtml('Those Slack members will be able to act as their QueryProxy users from T0ABC12.');
});

test('the bind action gives every unbound Slack ID the entered workspace, audited, and leaves the rest', function () {
    [, $dba, $developer, $admin] = uiSlackSetup();
    $one = User::factory()->create();
    $two = User::factory()->create();
    $teamsUser = User::factory()->create();
    ChatIdentity::create(['user_id' => $one->id, 'provider' => 'slack', 'external_id' => 'U_ONE']);
    ChatIdentity::create(['user_id' => $two->id, 'provider' => 'slack', 'external_id' => 'U_TWO']);
    ChatIdentity::create(['user_id' => $teamsUser->id, 'provider' => 'teams', 'external_id' => 'AAD_ONE']);

    Livewire::actingAs($admin)->test(Users::class)
        ->set('slackWorkspaceId', 't_bound')
        ->call('bindUnboundSlackIdentities')
        ->assertHasNoErrors();

    $logs = AuditLog::where('action', 'chat_identity.linked')->where('metadata->source', 'workspace_bind')->get();

    expect(ChatIdentity::whereIn('external_id', ['U_ONE', 'U_TWO'])->pluck('team_id')->unique()->all())->toBe(['T_BOUND'])
        // Already bound identities and Teams identities are not touched.
        ->and(ChatIdentity::where('user_id', $dba->id)->sole()->team_id)->toBe(SlackApp::WORKSPACE)
        ->and(ChatIdentity::where('provider', 'teams')->sole()->team_id)->toBeNull()
        ->and($logs)->toHaveCount(2)
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and($logs->first()->metadata['slack_team_id'])->toBe('T_BOUND');
});

test('the bind action needs a valid workspace and an admin', function () {
    [, , $developer, $admin] = uiSlackSetup();
    SlackApp::install(Team::factory()->create(), ['slack_team_id' => 'T_SECOND']);
    $person = User::factory()->create();
    ChatIdentity::create(['user_id' => $person->id, 'provider' => 'slack', 'external_id' => 'U_PASSIVE']);

    // Nothing entered: no fallback to an installed workspace (two here, and the same with one);
    // an enterprise id is not a workspace id.
    Livewire::actingAs($admin)->test(Users::class)
        ->call('bindUnboundSlackIdentities')->assertHasErrors('chat_id')
        ->set('slackWorkspaceId', 'E0123ABC')->call('bindUnboundSlackIdentities')->assertHasErrors('chat_id');

    expect(ChatIdentity::where('user_id', $person->id)->sole()->team_id)->toBeNull();

    Livewire::actingAs($developer)->test(Users::class)->set('slackWorkspaceId', 'T_BOUND')->call('bindUnboundSlackIdentities')->assertForbidden();
});

test('a failed Slack ID link does not clear the error of the new user form', function () {
    [, , , $admin] = uiSlackSetup();
    $person = User::factory()->create();

    Livewire::actingAs($admin)->test(Users::class)
        ->set('email', 'not-an-email')
        ->call('createUser')
        ->assertHasErrors('email')
        ->call('updateSlackId', $person->id, 'U_SOMEONE')
        ->assertHasErrors('email');
});

test('the bind action never falls back to the one installed workspace', function () {
    [, , , $admin] = uiSlackSetup();
    $person = User::factory()->create();
    ChatIdentity::create(['user_id' => $person->id, 'provider' => 'slack', 'external_id' => 'U_PASSIVE']);

    Livewire::actingAs($admin)->test(Users::class)
        ->call('bindUnboundSlackIdentities')
        ->assertHasErrors('chat_id');

    expect(ChatIdentity::where('user_id', $person->id)->sole()->team_id)->toBeNull();
});
