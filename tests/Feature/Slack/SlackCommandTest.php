<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\SlackRequestForm;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SlackApp;

function slashCommand(array $overrides = []): array
{
    return [
        'command' => '/queryproxy',
        'text' => '',
        'team_id' => SlackApp::WORKSPACE,
        'user_id' => 'U_DEV',
        'trigger_id' => 'trigger-1',
        ...$overrides,
    ];
}

function viewSubmission(string $slackUserId, mixed $connectionId, ?string $sql, ?string $reason = null, string $workspace = SlackApp::WORKSPACE): array
{
    return ['payload' => json_encode([
        'type' => 'view_submission',
        'team' => ['id' => $workspace],
        'user' => ['id' => $slackUserId],
        'view' => [
            'callback_id' => SlackRequestForm::CALLBACK_ID,
            'state' => ['values' => [
                SlackRequestForm::BLOCK_CONNECTION => ['connection' => ['selected_option' => $connectionId === null ? null : ['value' => (string) $connectionId]]],
                SlackRequestForm::BLOCK_SQL => ['sql' => ['value' => $sql]],
                SlackRequestForm::BLOCK_REASON => ['reason' => ['value' => $reason]],
            ]],
        ],
    ])];
}

/** The developer of a team may use one connection; a second one is not granted. */
function slackFormSetup(): array
{
    [$team, $dba, $developer, $integration] = SlackApp::team();

    $granted = Connection::factory()->create(['team_id' => $team->id, 'name' => 'Granted DB']);
    $notGranted = Connection::factory()->create(['team_id' => $team->id, 'name' => 'Hidden DB']);
    $granted->grantedUsers()->attach($developer->id, ['access_level' => 'write']);

    return [$team, $dba, $developer, $granted, $notGranted];
}

test('an unsigned slash command is refused with 401', function () {
    SlackApp::configure();
    Http::fake();

    $this->post(route('webhooks.slack.commands'), slashCommand())->assertUnauthorized();

    Http::assertNothingSent();
});

test('a slash command signed with the wrong secret is refused with 401', function () {
    SlackApp::configure();
    Http::fake();

    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(), 'wrong-secret')->assertUnauthorized();

    Http::assertNothingSent();
});

test('a slash command with a stale timestamp is refused with 401', function () {
    SlackApp::configure();
    Http::fake();

    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(), null, now()->getTimestamp() - 600)->assertUnauthorized();
    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(), null, now()->getTimestamp() + 600)->assertUnauthorized();

    Http::assertNothingSent();
});

test('a team signing secret cannot sign slash commands', function () {
    [$team] = SlackApp::team(['signing_secret' => 'team-own-secret', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X']);
    Http::fake();

    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(), 'team-own-secret')->assertUnauthorized();

    Http::assertNothingSent();
});

test('a linked developer gets the modal with only the connections they may use', function () {
    SlackApp::fakeApi();

    [, , , $granted, $notGranted] = slackFormSetup();

    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand())->assertOk();

    Http::assertSent(function (Request $sent) use ($granted, $notGranted) {
        if (! str_ends_with($sent->url(), '/api/views.open')) {
            return false;
        }

        $options = $sent['view']['blocks'][0]['element']['options'];
        $values = array_column($options, 'value');

        return $sent['trigger_id'] === 'trigger-1'
            && $sent->header('Authorization') === ['Bearer '.SlackApp::BOT_TOKEN]
            && $sent['view']['callback_id'] === SlackRequestForm::CALLBACK_ID
            && $values === [(string) $granted->id]
            && ! in_array((string) $notGranted->id, $values, true);
    });
});

test('a DBA sees every connection of the team', function () {
    SlackApp::fakeApi();

    [, , , $granted, $notGranted] = slackFormSetup();

    SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(['user_id' => 'U_DBA']))->assertOk();

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/api/views.open')
        && array_column($sent['view']['blocks'][0]['element']['options'], 'value') === [(string) $granted->id, (string) $notGranted->id]);
});

test('a Slack member without a chat identity gets an ephemeral error and no modal', function () {
    SlackApp::fakeApi();

    slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(['user_id' => 'U_STRANGER']));

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($response->json('text'))->toContain('not linked');

    Http::assertNothingSent();
});

test('a suspended user gets no modal', function () {
    SlackApp::fakeApi();

    [, , $developer] = slackFormSetup();
    $developer->forceFill(['suspended_at' => now()])->save();

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand());

    expect($response->json('response_type'))->toBe('ephemeral');
    Http::assertNothingSent();
});

test('a user with no usable connection gets an ephemeral message', function () {
    SlackApp::fakeApi();

    [, , $developer] = SlackApp::team();
    Connection::factory()->create(['team_id' => $developer->teams()->first()->id]);

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand());

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($response->json('text'))->toContain('no connection');
    Http::assertNothingSent();
});

test('a workspace the app is not installed in gets an ephemeral message', function () {
    SlackApp::fakeApi();

    slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(['team_id' => 'T_NOT_INSTALLED']));

    expect($response->json('text'))->toContain('not installed');
    Http::assertNothingSent();
});

test('a modal submission files a request through the submission service with channel slack', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer, $granted] = slackFormSetup();

    SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'DELETE FROM orders WHERE id = 7', 'Fix a bad row'))
        ->assertOk();

    $request = QueryRequest::sole();

    expect($request->user_id)->toBe($developer->id)
        ->and($request->team_id)->toBe($team->id)
        ->and($request->connection_id)->toBe($granted->id)
        ->and($request->note)->toBe('Fix a bad row')
        ->and($request->status->value)->toBe('pending')
        ->and(AuditLog::where('action', 'request.submitted')->sole()->metadata['channel'])->toBe('slack');

    // The usual approval card goes out for the new request.
    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/api/chat.postMessage') && $sent['channel'] === SlackApp::CHANNEL);
});

test('a modal submission from an unlinked member opens no request', function () {
    SlackApp::fakeApi();

    [, , , $granted] = slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_STRANGER', $granted->id, 'SELECT 1'));

    expect($response->json('response_action'))->toBe('errors')
        ->and($response->json('errors.'.SlackRequestForm::BLOCK_CONNECTION))->toContain('not linked')
        ->and(QueryRequest::count())->toBe(0);
});

test('a modal submission naming a connection the member may not use opens no request', function () {
    SlackApp::fakeApi();

    [, , , , $notGranted] = slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $notGranted->id, 'SELECT 1'));

    expect($response->json('response_action'))->toBe('errors')
        ->and($response->json('errors.'.SlackRequestForm::BLOCK_CONNECTION))->not->toBeNull()
        ->and(QueryRequest::count())->toBe(0);
});

test('a modal submission naming a connection of a team outside the workspace opens no request', function () {
    SlackApp::fakeApi();

    slackFormSetup();

    $otherTeam = Team::factory()->create();
    $foreign = Connection::factory()->create(['team_id' => $otherTeam->id]);
    $dba = User::factory()->create();
    $otherTeam->users()->attach($dba, ['role' => 'dba']);
    ChatIdentity::create(['user_id' => $dba->id, 'provider' => 'slack', 'external_id' => 'U_OTHER_DBA']);

    // A DBA of another team has the right in QueryProxy, but that team has no
    // installation in this workspace.
    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_OTHER_DBA', $foreign->id, 'SELECT 1'));

    expect($response->json('response_action'))->toBe('errors')
        ->and(QueryRequest::count())->toBe(0);
});

test('a modal submission with a non-numeric connection opens no request', function () {
    SlackApp::fakeApi();

    slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', '1 OR 1=1', 'SELECT 1'));

    expect($response->json('response_action'))->toBe('errors')
        ->and(QueryRequest::count())->toBe(0);
});

test('a statement the SQL guard refuses keeps the modal open with the reason', function () {
    SlackApp::fakeApi();

    [, , , $granted] = slackFormSetup();

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'DROP DATABASE app'));

    expect($response->json('response_action'))->toBe('errors')
        ->and($response->json('errors.'.SlackRequestForm::BLOCK_SQL))->not->toBeEmpty()
        ->and(QueryRequest::count())->toBe(0);
});

test('a read-only grant cannot file a write through the modal', function () {
    SlackApp::fakeApi();

    [, , $developer, $granted] = slackFormSetup();
    $granted->grantedUsers()->updateExistingPivot($developer->id, ['access_level' => 'read']);

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'DELETE FROM orders WHERE id = 7'));

    expect($response->json('response_action'))->toBe('errors')
        ->and($response->json('errors.'.SlackRequestForm::BLOCK_SQL))->toContain('read-only')
        ->and(QueryRequest::count())->toBe(0);
});

test('an unsigned modal submission is refused', function () {
    SlackApp::configure();

    [, , , $granted] = slackFormSetup();

    $this->post(route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'SELECT 1'))->assertUnauthorized();

    expect(QueryRequest::count())->toBe(0);
});

test('a modal submission signed with a team secret is refused', function () {
    [, , , $granted] = slackFormSetup();
    $other = Team::factory()->create();
    ChatIntegration::create([
        'team_id' => $other->id, 'provider' => 'slack', 'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'signing_secret' => 'team-own-secret', 'enabled' => true,
    ]);

    SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'SELECT 1'), 'team-own-secret')
        ->assertUnauthorized();

    expect(QueryRequest::count())->toBe(0);
});

test('a modal submission answers before the cards and direct messages go out', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , , $granted] = slackFormSetup();
    ChatIntegration::where('team_id', $team->id)->update(['dm_approvers' => true]);

    // The service directly, without a request cycle: deferred work is not run.
    $result = app(SlackRequestForm::class)->submit(json_decode(viewSubmission('U_DEV', $granted->id, 'DELETE FROM orders WHERE id = 7')['payload'], true));

    expect($result)->toBe([])
        ->and(QueryRequest::count())->toBe(1);

    Http::assertNothingSent();

    app(DeferredCallbackCollection::class)->invoke();

    $channels = Http::recorded()->map(fn ($pair) => $pair[0]['channel'])->all();

    expect($channels)->toBe([SlackApp::CHANNEL, 'U_DBA']);
});

test('the web UI still sends its chat messages straight away', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer, $granted] = slackFormSetup();

    app(RequestSubmissionService::class)->submit($developer, $granted, 'SELECT 1', null);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/api/chat.postMessage'));
});

test('pressing Submit again on the same modal does not file a second request', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [, , , $granted] = slackFormSetup();

    $fields = viewSubmission('U_DEV', $granted->id, 'DELETE FROM orders WHERE id = 7');
    $payload = json_decode($fields['payload'], true);
    $payload['view']['id'] = 'V_ONE';
    $fields = ['payload' => json_encode($payload)];

    SlackApp::postForm($this, route('webhooks.slack'), $fields)->assertOk();
    $again = SlackApp::postForm($this, route('webhooks.slack'), $fields);

    $again->assertOk();

    expect($again->getContent())->toBe('')
        ->and(QueryRequest::count())->toBe(1);

    // Another modal is another request.
    $payload['view']['id'] = 'V_TWO';
    SlackApp::postForm($this, route('webhooks.slack'), ['payload' => json_encode($payload)])->assertOk();

    expect(QueryRequest::count())->toBe(2);
});

test('a refused modal submission can be corrected and sent again from the same modal', function () {
    Notification::fake();
    SlackApp::fakeApi();

    [, , , $granted] = slackFormSetup();

    $send = function (string $sql) use ($granted) {
        $payload = json_decode(viewSubmission('U_DEV', $granted->id, $sql)['payload'], true);
        $payload['view']['id'] = 'V_RETRY';

        return SlackApp::postForm($this, route('webhooks.slack'), ['payload' => json_encode($payload)]);
    };

    expect($send('DROP DATABASE app')->json('response_action'))->toBe('errors')
        ->and(QueryRequest::count())->toBe(0);

    $send('DELETE FROM orders WHERE id = 7')->assertOk();

    expect(QueryRequest::count())->toBe(1);
});

// --- Slack identities are bound to a workspace (plan v0.4.2, Faz 03) -------------------------

/** A Slack member id means nothing outside the workspace it was linked in. */
function pressApprove(object $test, $request, string $token, string $workspace, string $slackUserId = 'U_DBA')
{
    return SlackApp::postForm($test, route('webhooks.slack'), ['payload' => json_encode([
        'type' => 'block_actions',
        'team' => ['id' => $workspace],
        'user' => ['id' => $slackUserId],
        'actions' => [['action_id' => 'queryproxy_approve', 'value' => "approve:{$request->id}:{$token}"]],
    ])]);
}

test('an interaction from another workspace does not match an identity with the same Slack user id', function () {
    Queue::fake();
    Notification::fake();
    SlackApp::fakeApi();

    // The team moved to another workspace; its members' identities still carry the old one.
    [$team, , $developer] = SlackApp::team(['slack_team_id' => 'T_OTHER']);
    $request = SlackApp::request($team, $developer);

    $response = pressApprove($this, $request, ChatApprovalToken::issueFor($request), 'T_OTHER');

    expect($response->json('text'))->toContain('not linked')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    Queue::assertNothingPushed();
});

test('the same interaction from the workspace the identity was linked in is decided', function () {
    Queue::fake();
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    $request = SlackApp::request($team, $developer);

    pressApprove($this, $request, ChatApprovalToken::issueFor($request), SlackApp::WORKSPACE)->assertOk();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued);
});

test('an identity without a workspace matches no interaction', function () {
    Queue::fake();
    Notification::fake();
    SlackApp::fakeApi();

    [$team, , $developer] = SlackApp::team();
    ChatIdentity::where('external_id', 'U_DBA')->update(['team_id' => null]);
    $request = SlackApp::request($team, $developer);

    $response = pressApprove($this, $request, ChatApprovalToken::issueFor($request), SlackApp::WORKSPACE);

    expect($response->json('text'))->toContain('not linked')
        ->and($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('a slash command from another workspace does not match an identity with the same Slack user id', function () {
    SlackApp::fakeApi();

    slackFormSetup();
    // A second team installed the app in another workspace; U_DEV is linked in the first one only.
    SlackApp::install(Team::factory()->create(), ['slack_team_id' => 'T_OTHER']);

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand(['team_id' => 'T_OTHER']));

    expect($response->json('response_type'))->toBe('ephemeral')
        ->and($response->json('text'))->toContain('not linked');

    Http::assertNothingSent();
});

test('a slash command from an identity without a workspace opens no modal', function () {
    SlackApp::fakeApi();

    slackFormSetup();
    ChatIdentity::where('external_id', 'U_DEV')->update(['team_id' => null]);

    $response = SlackApp::postForm($this, route('webhooks.slack.commands'), slashCommand());

    expect($response->json('text'))->toContain('not linked');

    Http::assertNothingSent();
});

test('a modal submission from another workspace opens no request', function () {
    SlackApp::fakeApi();

    [$team, , , $granted] = slackFormSetup();
    SlackApp::install(Team::factory()->create(), ['slack_team_id' => 'T_OTHER']);

    $response = SlackApp::postForm($this, route('webhooks.slack'), viewSubmission('U_DEV', $granted->id, 'SELECT 1', null, 'T_OTHER'));

    expect($response->json('errors.'.SlackRequestForm::BLOCK_CONNECTION))->toContain('not linked')
        ->and(QueryRequest::count())->toBe(0);
});
