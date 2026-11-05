<?php

use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const SLACK_MIGRATIONS = [
    'database/migrations/2026_11_01_000021_create_slack_identity_suggestions_table.php',
    'database/migrations/2026_11_01_000020_add_slack_app_columns.php',
];

test('the Slack app migrations roll back and apply again without touching webhook integrations', function () {
    $team = Team::factory()->create();
    $webhook = ChatIntegration::create([
        'team_id' => $team->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'signing_secret' => 'secret', 'enabled' => true,
    ]);

    $appOnly = ChatIntegration::create([
        'team_id' => Team::factory()->create()->id, 'provider' => 'slack', 'bot_token' => 'xoxb-1', 'slack_team_id' => 'T1', 'enabled' => true,
    ]);

    foreach (SLACK_MIGRATIONS as $path) {
        $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => false])->assertSuccessful();
    }

    expect(Schema::hasTable('slack_identity_suggestions'))->toBeFalse()
        ->and(Schema::hasColumns('chat_integrations', ['bot_token', 'slack_team_id', 'dm_approvers']))->toBeFalse()
        ->and(Schema::hasColumns('query_requests', ['slack_channel', 'slack_ts']))->toBeFalse()
        // The webhook integration survives; the one that only the app could create does not.
        ->and(DB::table('chat_integrations')->pluck('id')->all())->toBe([$webhook->id]);

    foreach (array_reverse(SLACK_MIGRATIONS) as $path) {
        $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
    }

    expect(Schema::hasTable('slack_identity_suggestions'))->toBeTrue()
        ->and(Schema::hasColumns('chat_integrations', ['bot_token', 'bot_user_id', 'slack_team_id', 'slack_channel_id', 'dm_approvers']))->toBeTrue()
        ->and(ChatIntegration::find($webhook->id)->webhook_url)->toBe('https://hooks.slack.com/services/T/B/X')
        ->and($appOnly->id)->not->toBeNull();
});

const CHAT_IDENTITY_WORKSPACE_MIGRATION = 'database/migrations/2026_11_04_000002_add_team_id_to_chat_identities.php';

test('the chat identity workspace migration rolls back and applies again', function () {
    $user = User::factory()->create();
    ChatIdentity::create(['user_id' => $user->id, 'provider' => 'slack', 'external_id' => 'U_KEEP', 'team_id' => 'T1']);

    $this->artisan('migrate:rollback', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(Schema::hasColumn('chat_identities', 'team_id'))->toBeFalse()
        // The rest of the table is untouched.
        ->and(DB::table('chat_identities')->where('external_id', 'U_KEEP')->exists())->toBeTrue();

    $this->artisan('migrate', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(Schema::hasColumn('chat_identities', 'team_id'))->toBeTrue();
});

test('the backfill gives existing Slack identities the workspace their team installed the app in, and leaves Teams alone', function () {
    $team = Team::factory()->create();
    ChatIntegration::create(['team_id' => $team->id, 'provider' => 'slack', 'bot_token' => 'xoxb-1', 'slack_team_id' => 'T_INSTALLED', 'enabled' => true]);

    $slackUser = User::factory()->create();
    $teamsUser = User::factory()->create();
    $team->users()->attach($slackUser, ['role' => 'developer']);

    $this->artisan('migrate:rollback', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    DB::table('chat_identities')->insert([
        ['user_id' => $slackUser->id, 'provider' => 'slack', 'external_id' => 'U_OLD'],
        ['user_id' => $teamsUser->id, 'provider' => 'teams', 'external_id' => 'AAD_OLD'],
    ]);

    $this->artisan('migrate', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(ChatIdentity::where('external_id', 'U_OLD')->sole()->team_id)->toBe('T_INSTALLED')
        ->and(ChatIdentity::where('external_id', 'AAD_OLD')->sole()->team_id)->toBeNull();
});

test('the backfill leaves the workspace empty when no installation names one', function () {
    $team = Team::factory()->create();
    // Webhook mode and a removed installation: no workspace to name.
    ChatIntegration::create(['team_id' => $team->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X', 'signing_secret' => 's', 'enabled' => true]);
    $user = User::factory()->create();

    $this->artisan('migrate:rollback', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();
    DB::table('chat_identities')->insert(['user_id' => $user->id, 'provider' => 'slack', 'external_id' => 'U_OLD']);
    $this->artisan('migrate', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(ChatIdentity::where('external_id', 'U_OLD')->sole()->team_id)->toBeNull();
});

test('with several workspaces the backfill follows the teams of the user', function () {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();
    ChatIntegration::create(['team_id' => $teamA->id, 'provider' => 'slack', 'bot_token' => 'xoxb-a', 'slack_team_id' => 'T_A', 'enabled' => true]);
    ChatIntegration::create(['team_id' => $teamB->id, 'provider' => 'slack', 'bot_token' => 'xoxb-b', 'slack_team_id' => 'T_B', 'enabled' => true]);

    $inA = User::factory()->create();
    $inBoth = User::factory()->create();
    $inNone = User::factory()->create();
    $teamA->users()->attach($inA, ['role' => 'developer']);
    $teamA->users()->attach($inBoth, ['role' => 'developer']);
    $teamB->users()->attach($inBoth, ['role' => 'developer']);

    $this->artisan('migrate:rollback', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();
    DB::table('chat_identities')->insert([
        ['user_id' => $inA->id, 'provider' => 'slack', 'external_id' => 'U_A'],
        ['user_id' => $inBoth->id, 'provider' => 'slack', 'external_id' => 'U_BOTH'],
        ['user_id' => $inNone->id, 'provider' => 'slack', 'external_id' => 'U_NONE'],
    ]);
    $this->artisan('migrate', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(ChatIdentity::where('external_id', 'U_A')->sole()->team_id)->toBe('T_A')
        // Ambiguous or unknown: left for an admin to link again.
        ->and(ChatIdentity::where('external_id', 'U_BOTH')->sole()->team_id)->toBeNull()
        ->and(ChatIdentity::where('external_id', 'U_NONE')->sole()->team_id)->toBeNull();
});

test('a user of a team in webhook mode is not given the workspace of another team', function () {
    $installed = Team::factory()->create();
    $webhook = Team::factory()->create();
    ChatIntegration::create(['team_id' => $installed->id, 'provider' => 'slack', 'bot_token' => 'xoxb-1', 'slack_team_id' => 'T_ONE', 'enabled' => true]);
    ChatIntegration::create(['team_id' => $webhook->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X', 'signing_secret' => 's', 'enabled' => true]);

    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $installed->users()->attach($mine, ['role' => 'developer']);
    $webhook->users()->attach($theirs, ['role' => 'developer']);

    $this->artisan('migrate:rollback', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();
    DB::table('chat_identities')->insert([
        ['user_id' => $mine->id, 'provider' => 'slack', 'external_id' => 'U_MINE'],
        ['user_id' => $theirs->id, 'provider' => 'slack', 'external_id' => 'U_THEIRS'],
    ]);
    $this->artisan('migrate', ['--path' => CHAT_IDENTITY_WORKSPACE_MIGRATION])->assertSuccessful();

    expect(ChatIdentity::where('external_id', 'U_MINE')->sole()->team_id)->toBe('T_ONE')
        ->and(ChatIdentity::where('external_id', 'U_THEIRS')->sole()->team_id)->toBeNull();
});

test('migrate:rollback --step=1 undoes the chat identity workspace migration', function () {
    $files = glob(database_path('migrations/*.php'));
    sort($files);

    // --step=1 undoes the last migration; this test only holds while it is this one.
    if (basename(end($files)) !== basename(CHAT_IDENTITY_WORKSPACE_MIGRATION)) {
        $this->markTestSkipped('A later migration exists; the --path rollback test covers this one.');
    }

    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

    expect(Schema::hasColumn('chat_identities', 'team_id'))->toBeFalse();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasColumn('chat_identities', 'team_id'))->toBeTrue();
});
