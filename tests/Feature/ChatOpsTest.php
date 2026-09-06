<?php

use App\Enums\QueryRequestStatus;
use App\Livewire\Studio\QueryStudio;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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

function slackPayload(QueryRequest $request, string $action, string $slackUserId): string
{
    return http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => $slackUserId],
        'actions' => [['action_id' => "queryproxy_{$action}", 'value' => "{$action}:{$request->id}"]],
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

test('a correctly signed teams action approves the request', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [, $dba, , $request] = chatSetup();

    $body = json_encode([
        'action' => 'approve',
        'request_id' => $request->id,
        'actor_email' => $dba->email,
    ]);

    $signature = base64_encode(hash_hmac('sha256', $body, TEAMS_SECRET, true));

    $this->call('POST', route('webhooks.teams'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'HMAC '.$signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->fresh()->review_channel)->toBe('teams');
});

test('teams requests with a bad hmac are rejected', function () {
    [, $dba, , $request] = chatSetup();

    $body = json_encode(['action' => 'approve', 'request_id' => $request->id, 'actor_email' => $dba->email]);

    $this->call('POST', route('webhooks.teams'), [], [], [], [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', $body, 'wrong', true)),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

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
