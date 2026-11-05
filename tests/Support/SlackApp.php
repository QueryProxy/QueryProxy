<?php

namespace Tests\Support;

use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fixtures for the Slack app tests (ADR-027): the app configuration, a team
 * with an installed bot, and requests signed the way Slack signs them.
 */
class SlackApp
{
    public const SIGNING_SECRET = 'test-app-signing-secret';

    public const WORKSPACE = 'T_WORKSPACE';

    public const BOT_TOKEN = 'xoxb-test-bot-token';

    public const CHANNEL = 'C_QUERYPROXY';

    public const TS = '1700000000.000100';

    public static function configure(): void
    {
        config([
            'services.slack.app.client_id' => 'client-id',
            'services.slack.app.client_secret' => 'client-secret',
            'services.slack.app.signing_secret' => self::SIGNING_SECRET,
        ]);
    }

    /**
     * A team with a DBA (Slack member U_DBA), a developer (U_DEV) and the app
     * installed. The suffix keeps member ids apart when a test needs two teams.
     *
     * @return array{0: Team, 1: User, 2: User, 3: ChatIntegration}
     */
    public static function team(array $integration = [], string $memberSuffix = ''): array
    {
        self::configure();

        $team = Team::factory()->create();
        $dba = User::factory()->create();
        $developer = User::factory()->create();
        $team->users()->attach($dba, ['role' => 'dba']);
        $team->users()->attach($developer, ['role' => 'developer']);

        ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_DBA'.$memberSuffix, 'team_id' => self::WORKSPACE]);
        ChatIdentity::create(['user_id' => $developer->id, 'provider' => 'slack', 'external_id' => 'U_DEV'.$memberSuffix, 'team_id' => self::WORKSPACE]);

        return [$team, $dba, $developer, self::install($team, $integration)];
    }

    public static function install(Team $team, array $overrides = []): ChatIntegration
    {
        return ChatIntegration::create([
            'team_id' => $team->id,
            'provider' => 'slack',
            'enabled' => true,
            'bot_token' => self::BOT_TOKEN,
            'bot_user_id' => 'U_BOT',
            'slack_team_id' => self::WORKSPACE,
            'slack_team_name' => 'Acme',
            'slack_channel_id' => self::CHANNEL,
            ...$overrides,
        ]);
    }

    /** A pending request whose card has already been posted to Slack. */
    public static function request(Team $team, User $requester, array $attributes = []): QueryRequest
    {
        return QueryRequest::factory()->create([
            'team_id' => $team->id,
            'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
            'user_id' => $requester->id,
            'slack_channel' => self::CHANNEL,
            'slack_ts' => self::TS,
            ...$attributes,
        ]);
    }

    /** Every Slack Web API call answers ok. */
    public static function fakeApi(array $overrides = []): void
    {
        // The union keeps an override ahead of (and in place of) the default of the same URL.
        Http::fake($overrides + [
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => self::CHANNEL, 'ts' => self::TS]),
            'slack.com/api/*' => Http::response(['ok' => true]),
        ]);
    }

    public static function sign(string $body, ?string $secret = null, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'HTTP_X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret ?? self::SIGNING_SECRET),
            'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        ];
    }

    /** POST a form body (slash command, interactivity) signed as Slack signs it. */
    public static function postForm(TestCase $test, string $url, array $fields, ?string $secret = null, ?int $timestamp = null)
    {
        $body = http_build_query($fields);

        return $test->call('POST', $url, $fields, [], [], [
            ...self::sign($body, $secret, $timestamp),
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    /** POST a JSON body (Events API) signed as Slack signs it. */
    public static function postJson(TestCase $test, string $url, array $payload, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode($payload);

        return $test->call('POST', $url, [], [], [], [
            ...self::sign($body, $secret, $timestamp),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
