<?php

use App\Enums\QueryRequestStatus;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestReminder;
use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Team with a DBA, a team admin, a developer, Slack + Teams (Workflows)
 * integrations, and one pending request that the developer filed.
 *
 * @return array{0: Team, 1: User, 2: User, 3: User, 4: QueryRequest}
 */
function remindSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($admin, ['role' => 'developer']);
    $team->users()->attach($developer, ['role' => 'developer']);

    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'slack-secret',
        'enabled' => true,
    ]);
    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'teams',
        'webhook_url' => 'https://prod-12.westeurope.logic.azure.com/workflows/abc/triggers/manual/paths/invoke?sig=s',
        'signing_secret' => 'teams-secret',
        'enabled' => true,
    ]);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
    ]);

    // What ChatNotifier::requestSubmitted issued when the request was filed:
    // one token per integration.
    ChatApprovalToken::issueFor($request);
    ChatApprovalToken::issueFor($request);

    return [$team, $dba, $admin, $developer, $request];
}

test('a request pending past the threshold is reminded about, in-app and in chat', function () {
    Http::fake();
    Notification::fake();
    [, $dba, $admin, $developer, $request] = remindSetup();

    $this->travel(5)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Notification::assertSentTo($dba, QueryRequestReminder::class);
    Notification::assertSentTo($admin, QueryRequestReminder::class);
    Notification::assertNotSentTo($developer, QueryRequestReminder::class);

    Http::assertSentCount(2);
    Http::assertSent(fn ($sent) => str_contains($sent->url(), 'hooks.slack.com') && str_contains($sent->body(), 'Reminder') && str_contains($sent->body(), ' UTC.'));
    Http::assertSent(fn ($sent) => str_contains($sent->url(), 'logic.azure.com') && str_contains($sent->body(), 'Reminder'));

    expect($request->fresh()->reminded_at)->not->toBeNull();
});

test('the reminder carries a fresh action token', function () {
    Http::fake();
    [, , , , $request] = remindSetup();
    $before = ChatApprovalToken::count();

    $this->travel(5)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    // One new token per integration.
    expect(ChatApprovalToken::count())->toBe($before + 2);
});

test('the reminder is not sent before the threshold', function () {
    Http::fake();
    Notification::fake();
    [, , , , $request] = remindSetup();

    $this->travel(3)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Notification::assertNothingSent();
    Http::assertNothingSent();
    expect($request->fresh()->reminded_at)->toBeNull();
});

test('the threshold follows QUERYPROXY_PENDING_REMIND_AFTER', function () {
    Http::fake();
    Notification::fake();
    [, , , , $request] = remindSetup();

    config(['queryproxy.pending_remind_after_hours' => 8]);
    $this->travel(6)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travel(3)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Notification::assertSentTimes(QueryRequestReminder::class, 2);
});

test('0 turns the reminder off but not the card renewal', function () {
    Http::fake();
    Notification::fake();
    config(['queryproxy.pending_remind_after_hours' => 0]);
    [, , , , $request] = remindSetup();

    // Buttons from the submit message are still live: nothing to do.
    $this->travel(10)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertNothingSent();

    // They timed out (24h): the card is re-posted, but nobody is reminded.
    $this->travel(15)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Notification::assertNothingSent();
    Http::assertSentCount(2);
    Http::assertSent(fn ($sent) => str_contains($sent->body(), 'have expired'));
    expect($request->fresh()->reminded_at)->toBeNull()
        ->and($request->fresh()->chat_renewed_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a request is reminded about only once and never expires', function () {
    Http::fake();
    Notification::fake();
    [, , , , $request] = remindSetup();

    $this->travel(5)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Notification::assertSentTimes(QueryRequestReminder::class, 2);
    Http::assertSentCount(2);

    $this->travel(2)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(2);

    // Weeks later the in-app reminder has still gone out only once.
    $this->travel(30)->days();
    $this->artisan('requests:remind')->assertSuccessful();

    Notification::assertSentTimes(QueryRequestReminder::class, 2);
    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('decided requests are not reminded about', function () {
    Http::fake();
    Notification::fake();
    [, , , , $request] = remindSetup();
    $request->update(['status' => QueryRequestStatus::Approved]);

    $this->travel(10)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('a pending request with no live chat token gets a renewed card, repeatedly, at most daily', function () {
    Http::fake();
    Notification::fake();
    [, , , , $request] = remindSetup();

    $this->travel(5)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(2);

    // The reminder's tokens run out (24h) while the request is still pending.
    $this->travel(25)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Http::assertSentCount(4);
    Http::assertSent(fn ($sent) => str_contains($sent->body(), 'have expired'));
    // The renewal is a chat card, not another in-app notification.
    Notification::assertSentTimes(QueryRequestReminder::class, 2);

    $live = fn () => ChatApprovalToken::where('query_request_id', $request->id)
        ->where('expires_at', '>', now())->whereNull('used_at')->count();
    expect($live())->toBe(2);
    $firstRenewal = $request->fresh()->chat_renewed_at;
    expect($firstRenewal)->not->toBeNull();

    // While the renewed buttons are alive, nothing more is posted.
    $this->travel(12)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(4);

    // They lapse too: a second renewal, and chat_renewed_at moves forward.
    $this->travel(13)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(6);
    expect($live())->toBe(2)
        ->and($request->fresh()->chat_renewed_at->greaterThan($firstRenewal))->toBeTrue()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('renewal does not depend on the reminder having been sent', function () {
    Http::fake();
    config(['queryproxy.pending_remind_after_hours' => 100]);
    [, , , , $request] = remindSetup();

    $this->travel(25)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Http::assertSentCount(2);
    expect($request->fresh()->reminded_at)->toBeNull();
});

test('renewals are spaced at least 24 hours apart', function () {
    Http::fake();
    config(['queryproxy.pending_remind_after_hours' => 0]);
    [, , , , $request] = remindSetup();

    $this->travel(25)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(2);

    // All tokens die early (say a revoked secret rotation): still no repost
    // inside the renewal window.
    ChatApprovalToken::where('query_request_id', $request->id)->update(['expires_at' => now()->subMinute()]);
    $this->travel(2)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(2);

    $this->travel(23)->hours();
    $this->artisan('requests:remind')->assertSuccessful();
    Http::assertSentCount(4);
});

test('a team without an enabled integration does not consume the renewal', function () {
    Http::fake();
    [$team, , , , $request] = remindSetup();
    ChatIntegration::where('team_id', $team->id)->update(['enabled' => false]);

    $this->travel(25)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Http::assertNothingSent();
    expect($request->fresh()->chat_renewed_at)->toBeNull();

    // An integration is switched on later: the pending request now gets its card.
    ChatIntegration::where('team_id', $team->id)->update(['enabled' => true]);
    $this->artisan('requests:remind')->assertSuccessful();

    Http::assertSentCount(2);
    expect($request->fresh()->chat_renewed_at)->not->toBeNull();
});

test('decided requests are not given a renewed card', function () {
    Http::fake();
    [, , , , $request] = remindSetup();
    $request->update(['status' => QueryRequestStatus::Rejected]);

    $this->travel(30)->hours();
    $this->artisan('requests:remind')->assertSuccessful();

    Http::assertNothingSent();
});

test('an expired slack button on a pending request gets a pointer to the newer message', function () {
    [, $dba, , , $request] = remindSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_DBA']);

    $token = ChatApprovalToken::issueFor($request);
    ChatApprovalToken::where('query_request_id', $request->id)->update(['expires_at' => now()->subMinute()]);

    $body = http_build_query(['payload' => json_encode([
        'type' => 'block_actions',
        'user' => ['id' => 'U_DBA'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);
    $timestamp = time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'slack-secret');
    parse_str($body, $parameters);

    $response = $this->call('POST', route('webhooks.slack'), $parameters, [], [], [
        'HTTP_X-Slack-Signature' => $signature,
        'HTTP_X-Slack-Request-Timestamp' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);

    expect($response->json('text'))->toContain('this button has expired')->toContain('newer message')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('an expired teams token on a pending request gets a pointer to the newer message', function () {
    [, $dba, , , $request] = remindSetup();
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'teams', 'external_id' => 'AAD_DBA']);

    $token = ChatApprovalToken::issueFor($request);
    ChatApprovalToken::where('query_request_id', $request->id)->update(['expires_at' => now()->subMinute()]);

    $body = json_encode(['action' => 'approve', 'request_id' => $request->id, 'actor_id' => 'AAD_DBA', 'token' => $token]);
    $timestamp = time();
    $headers = [
        'HTTP_AUTHORIZATION' => 'HMAC '.base64_encode(hash_hmac('sha256', "{$timestamp}:{$body}", 'teams-secret', true)),
        'HTTP_X_QUERYPROXY_TIMESTAMP' => (string) $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ];

    $response = $this->call('POST', route('webhooks.teams'), [], [], [], $headers, $body);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('this button has expired')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('the reminder command is scheduled', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'requests:remind'));

    expect($events)->toHaveCount(1);
});

test('ChatNotifier requestReminder posts only to the request team', function () {
    Http::fake();
    [, , , , $request] = remindSetup();
    [, , , , $other] = remindSetup();

    app(ChatNotifier::class)->requestReminder($request);

    Http::assertSentCount(2);
});
