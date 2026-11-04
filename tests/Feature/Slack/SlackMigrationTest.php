<?php

use App\Models\ChatIntegration;
use App\Models\Team;
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
