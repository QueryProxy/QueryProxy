<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\AuditLog;
use App\Models\ChatApprovalToken;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Approvals\ApprovalService;
use App\Services\Chat\ChatNotifier;
use App\Services\Execution\BackendCanceller;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SlackApp;

/** @return list<Request> */
function slackCalls(string $method): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_ends_with($request->url(), "/api/{$method}"))
        ->values()
        ->all();
}

/** A request against a SQLite target database, ready to run. */
function slackExecutionRequest(string $sql): array
{
    Storage::fake('local');

    $file = tempnam(sys_get_temp_dir(), 'qp_slack_target_');
    $pdo = new PDO('sqlite:'.$file);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO customers (name) VALUES ('Ada'), ('Grace'), ('Alan')");
    unset($pdo);

    [$team, $dba, $developer] = SlackApp::team();

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->sqlite($file)->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'reviewed_by' => $dba->id,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
        'status' => QueryRequestStatus::Queued,
        'slack_channel' => SlackApp::CHANNEL,
        'slack_ts' => SlackApp::TS,
    ]);

    return [$request, $file];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_slack_target_*') ?: [] as $file) {
        @unlink($file);
    }
});

test('a new request card is posted with chat.postMessage and its channel and ts are stored', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);

    $posts = slackCalls('chat.postMessage');

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['channel'])->toBe(SlackApp::CHANNEL)
        ->and($posts[0]->header('Authorization'))->toBe(['Bearer '.SlackApp::BOT_TOKEN])
        ->and(json_encode($posts[0]['blocks']))->toContain('queryproxy_approve')
        ->and($request->fresh()->slack_channel)->toBe(SlackApp::CHANNEL)
        ->and($request->fresh()->slack_ts)->toBe(SlackApp::TS);
});

test('approving in the web UI updates the card at the stored ts', function () {
    Queue::fake();
    Notification::fake();
    SlackApp::fakeApi();

    [$team, $dba, $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    app(ApprovalService::class)->approve($request, $dba);

    $updates = slackCalls('chat.update');

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['channel'])->toBe(SlackApp::CHANNEL)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Approved by '.$dba->name)
        ->and(json_encode($updates[0]['blocks']))->not->toContain('queryproxy_approve')
        // No follow-up message per step.
        ->and(slackCalls('chat.postMessage'))->toBe([]);
});

test('rejecting updates the card', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, $dba, $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    app(ApprovalService::class)->reject($request, $dba, 'Not now.');

    $updates = slackCalls('chat.update');

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Rejected by '.$dba->name);
});

test('cancelling a pending request updates the card', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    app(ApprovalService::class)->cancel($request, $developer);

    $updates = slackCalls('chat.update');

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Cancelled');
});

test('a system cancellation updates the card', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    expect(app(ApprovalService::class)->cancelBySystem($request, 'owner deleted'))->toBeTrue();

    expect(slackCalls('chat.update'))->toHaveCount(1)
        ->and(slackCalls('chat.update')[0]['text'])->toContain('Cancelled');
});

test('a run updates the card to running, then completed, and replies in the thread without SQL', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$request] = slackExecutionRequest('SELECT id, name FROM customers ORDER BY id LIMIT 1000');

    ExecuteQueryRequest::dispatchSync($request);

    $updates = slackCalls('chat.update');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Completed)
        ->and($updates)->toHaveCount(2)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Running')
        ->and($updates[1]['ts'])->toBe(SlackApp::TS)
        ->and($updates[1]['text'])->toContain('Completed');

    $replies = slackCalls('chat.postMessage');

    expect($replies)->toHaveCount(1)
        ->and($replies[0]['channel'])->toBe(SlackApp::CHANNEL)
        ->and($replies[0]['thread_ts'])->toBe(SlackApp::TS)
        ->and($replies[0]['text'])->toContain('Completed')->toContain('3 rows')->toContain(route('requests.show', $request))
        // Status, rows, duration and link only: no SQL, no result data, no blocks.
        ->and($replies[0]['text'])->not->toContain('SELECT')->not->toContain('customers')->not->toContain('Ada')
        ->and($replies[0]->data())->not->toHaveKey('blocks');
});

test('a failed run updates the card and the thread reply carries no error text', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$request] = slackExecutionRequest('SELECT * FROM missing_table LIMIT 10');

    ExecuteQueryRequest::dispatchSync($request);

    $request->refresh();

    $updates = slackCalls('chat.update');
    $replies = slackCalls('chat.postMessage');

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->not->toBeEmpty()
        ->and(end($updates)['text'])->toContain('failed')
        ->and($replies)->toHaveCount(1)
        ->and($replies[0]['thread_ts'])->toBe(SlackApp::TS)
        ->and($replies[0]['text'])->toContain('Failed')->not->toContain('missing_table')->not->toContain($request->error_message);
});

test('a job that dies marks the request failed and the card follows', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$request] = slackExecutionRequest('SELECT 1');

    (new ExecuteQueryRequest($request))->failed(new RuntimeException('worker died'));

    $updates = slackCalls('chat.update');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Failed)
        ->and($updates)->toHaveCount(1)
        ->and($updates[0]['text'])->toContain('failed')
        ->and(slackCalls('chat.postMessage')[0]['text'])->toContain('Failed');
});

test('a duplicate job copy for a settled request does not announce a run', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$request] = slackExecutionRequest('SELECT 1');
    $request->update(['status' => QueryRequestStatus::Completed]);

    ExecuteQueryRequest::dispatchSync($request);

    expect(slackCalls('chat.update'))->toBe([]);
});

test('the webhook mode keeps posting to the webhook and never calls the Slack API', function () {
    Queue::fake();
    Notification::fake();
    Http::fake();

    [$team, $dba, $developer, $integration] = SlackApp::team();
    $integration->forceFill([
        'bot_token' => null, 'slack_channel_id' => null, 'slack_team_id' => null,
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'legacy-secret',
    ])->save();

    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);
    app(ApprovalService::class)->approve($request->fresh(), $dba);
    ApprovalNotifier::requestStarted($request->fresh());
    ApprovalNotifier::requestFinished($request->fresh());

    Http::assertSent(fn (Request $sent) => $sent->url() === 'https://hooks.slack.com/services/T000/B000/XXX');
    Http::assertNotSent(fn (Request $sent) => str_contains($sent->url(), 'slack.com/api'));

    // Submitted card and the decision text; nothing for start or finish.
    expect(Http::recorded())->toHaveCount(2);
});

test('a refused bot post falls back to the webhook when there is one', function () {
    Notification::fake();
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found']),
        'hooks.slack.com/*' => Http::response('ok'),
    ]);

    [$team, , $developer, $integration] = SlackApp::team(['webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX', 'signing_secret' => 's']);
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);

    Http::assertSent(fn (Request $sent) => str_starts_with($sent->url(), 'https://hooks.slack.com/services'));

    // A wrong channel is not a dead token: the installation stays.
    expect($integration->fresh()->bot_token)->toBe(SlackApp::BOT_TOKEN);
});

test('a token Slack rejects ends the installation and is audited without the token', function () {
    Notification::fake();
    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'token_revoked'])]);

    [$team, , $developer, $integration] = SlackApp::team();
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);

    $integration->refresh();
    $log = AuditLog::where('action', 'slack.uninstalled')->sole();

    expect($integration->bot_token)->toBeNull()
        ->and($integration->slack_team_id)->toBeNull()
        ->and($log->metadata['reason'])->toBe('token_rejected')
        ->and($log->user_id)->toBeNull()
        ->and(json_encode($log->metadata))->not->toContain(SlackApp::BOT_TOKEN);
});

test('approvers get a direct message when the team opted in, and the requester does not', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, $dba, $developer, $integration] = SlackApp::team(['dm_approvers' => true]);
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null, 'sql_prepared' => 'SELECT secret_column FROM payroll LIMIT 1']);

    app(ChatNotifier::class)->requestSubmitted($request);

    $channels = array_map(fn (Request $sent) => $sent['channel'], slackCalls('chat.postMessage'));
    $dm = collect(slackCalls('chat.postMessage'))->first(fn (Request $sent) => $sent['channel'] === 'U_DBA');

    expect($channels)->toBe([SlackApp::CHANNEL, 'U_DBA'])
        ->and($dm['text'])->toContain('request #'.$request->id)
        // Text and a link; no SQL and no approve buttons in a direct message.
        ->and(json_encode($dm->data()))->not->toContain('payroll')->not->toContain('queryproxy_approve')->not->toContain('queryproxy_reject');
});

test('no direct messages without the opt-in', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);

    expect(slackCalls('chat.postMessage'))->toHaveCount(1);
});

test('a decision from a Slack button does not overwrite the updated card', function () {
    Queue::fake();
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);
    $token = ChatApprovalToken::issueFor($request);

    $response = SlackApp::postForm($this, route('webhooks.slack'), ['payload' => json_encode([
        'type' => 'block_actions',
        'team' => ['id' => SlackApp::WORKSPACE],
        'user' => ['id' => 'U_DBA'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);

    $response->assertOk();

    expect($response->json('replace_original'))->toBeNull()
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Queued)
        ->and(slackCalls('chat.update'))->toHaveCount(1);
});

test('a click from another workspace cannot decide the request', function () {
    Queue::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);
    $token = ChatApprovalToken::issueFor($request);

    $response = SlackApp::postForm($this, route('webhooks.slack'), ['payload' => json_encode([
        'type' => 'block_actions',
        'team' => ['id' => 'T_OTHER'],
        'user' => ['id' => 'U_DBA'],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('stopping a running request updates the card to cancelled', function () {
    Notification::fake();
    SlackApp::fakeApi();
    $this->mock(BackendCanceller::class, fn ($mock) => $mock->shouldReceive('cancel')->once()->andReturn(true));

    [$team, $dba, $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer, [
        'status' => QueryRequestStatus::Running,
        'executed_at' => now()->subSeconds(3),
        'backend_pid' => 4242,
    ]);

    app(ApprovalService::class)->stop($request, $dba);

    $updates = slackCalls('chat.update');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled)
        ->and($updates)->toHaveCount(1)
        ->and($updates[0]['channel'])->toBe(SlackApp::CHANNEL)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Cancelled')
        ->and(json_encode($updates[0]['blocks']))->not->toContain('queryproxy_approve');
});

test('a reminder card takes the buttons and the stale status off the card before it', function () {
    Notification::fake();
    SlackApp::fakeApi(['slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => SlackApp::CHANNEL, 'ts' => '1700009999.000200'])]);

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    app(ChatNotifier::class)->requestReminder($request);

    $updates = slackCalls('chat.update');

    expect(slackCalls('chat.postMessage'))->toHaveCount(1)
        ->and($updates)->toHaveCount(1)
        ->and($updates[0]['ts'])->toBe(SlackApp::TS)
        ->and($updates[0]['text'])->toContain('Moved')
        ->and(json_encode($updates[0]['blocks']))->not->toContain('queryproxy_approve')
        ->and($request->fresh()->slack_ts)->toBe('1700009999.000200');

    // From now on the newer card is the one that follows the request.
    Http::fake(['slack.com/api/*' => Http::response(['ok' => true])]);
    Queue::fake();
    app(ApprovalService::class)->approve($request->fresh(), User::find($team->users()->wherePivot('role', 'dba')->first()->id));

    $later = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $sent) => str_ends_with($sent->url(), '/api/chat.update'));

    expect($later)->toHaveCount(1)
        ->and($later->first()['ts'])->toBe('1700009999.000200');
});

test('the first card has no earlier card to retire', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer, ['slack_channel' => null, 'slack_ts' => null]);

    app(ChatNotifier::class)->requestSubmitted($request);

    expect(slackCalls('chat.update'))->toBe([]);
});
