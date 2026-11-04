<?php

use App\Livewire\Admin\Users;
use App\Livewire\Settings\ChatOps;
use App\Models\AuditLog;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\SlackIdentitySuggestion;
use App\Models\User;
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
    uiSuggestion($team, $developer);

    Livewire::actingAs($dba)->test(ChatOps::class)
        ->call('uninstallSlack')
        ->assertHasNoErrors();

    $log = AuditLog::where('action', 'slack.uninstalled')->sole();

    expect(ChatIntegration::where('team_id', $team->id)->exists())->toBeFalse()
        ->and(SlackIdentitySuggestion::count())->toBe(0)
        ->and($log->user_id)->toBe($dba->id)
        ->and($log->team_id)->toBe($team->id)
        ->and($log->metadata)->toBe(['slack_team_id' => SlackApp::WORKSPACE, 'reason' => 'removed_by_user'])
        ->and(json_encode($log->metadata))->not->toContain('xoxb-');
});

test('removing the app keeps a webhook that was set up beside it', function () {
    [$team, $dba] = uiSlackSetup([
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'team-secret',
        'dm_approvers' => true,
    ]);

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

test('suggestions of another team in the same workspace survive a removal', function () {
    [$team, $dba, $developer] = uiSlackSetup();
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

    Livewire::actingAs($admin)->test(ChatOps::class)->call('remove', 'slack')->assertHasNoErrors();

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
    ChatIdentity::create(['user_id' => $linked->id, 'provider' => 'slack', 'external_id' => 'U_LINKED']);
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

test('rejecting a suggestion deletes it and links nothing', function () {
    [$team, , , $admin] = uiSlackSetup();
    $newcomer = User::factory()->create();
    $team->users()->attach($newcomer, ['role' => 'developer']);
    $suggestion = uiSuggestion($team, $newcomer, 'U_NEWCOMER');

    Livewire::actingAs($admin)->test(Users::class)
        ->call('rejectSlackSuggestion', $suggestion->id)
        ->assertHasNoErrors();

    expect(SlackIdentitySuggestion::count())->toBe(0)
        ->and(ChatIdentity::where('user_id', $newcomer->id)->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'chat_identity.linked')->exists())->toBeFalse();
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
