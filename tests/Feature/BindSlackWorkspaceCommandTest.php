<?php

use App\Models\AuditLog;
use App\Models\ChatIdentity;
use App\Models\Team;
use App\Models\User;
use Tests\Support\SlackApp;

function unboundSlackIdentity(string $externalId): ChatIdentity
{
    return ChatIdentity::create(['user_id' => User::factory()->create()->id, 'provider' => 'slack', 'external_id' => $externalId]);
}

test('the command binds every Slack identity without a workspace after confirmation', function () {
    $one = unboundSlackIdentity('U_ONE');
    $two = unboundSlackIdentity('U_TWO');
    $bound = ChatIdentity::create(['user_id' => User::factory()->create()->id, 'provider' => 'slack', 'external_id' => 'U_BOUND', 'team_id' => 'T_KEEP']);
    $teams = ChatIdentity::create(['user_id' => User::factory()->create()->id, 'provider' => 'teams', 'external_id' => 'AAD_ONE']);

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 't0123abc'])
        ->expectsConfirmation('Bind 2 Slack identities without a workspace to T0123ABC? Those Slack members will be able to act as their QueryProxy users from that workspace.', 'yes')
        ->expectsOutputToContain('Bound 2 Slack identities to T0123ABC.')
        ->assertSuccessful();

    $log = AuditLog::where('action', 'chat_identity.linked')->where('metadata->source', 'workspace_bind')->get();

    expect($one->fresh()->team_id)->toBe('T0123ABC')
        ->and($two->fresh()->team_id)->toBe('T0123ABC')
        ->and($bound->fresh()->team_id)->toBe('T_KEEP')
        ->and($teams->fresh()->team_id)->toBeNull()
        ->and($log)->toHaveCount(2)
        ->and($log->first()->metadata['actor'])->toBe('system');
});

test('declining the confirmation changes nothing', function () {
    $identity = unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC'])
        ->expectsConfirmation('Bind 1 Slack identity without a workspace to T0123ABC? Those Slack members will be able to act as their QueryProxy users from that workspace.', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    expect($identity->fresh()->team_id)->toBeNull();
});

test('--force skips the question', function () {
    $identity = unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC', '--force' => true])->assertSuccessful();

    expect($identity->fresh()->team_id)->toBe('T0123ABC');
});

test('a malformed workspace id is refused', function (string $workspaceId) {
    $identity = unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => $workspaceId, '--force' => true])->assertFailed();

    expect($identity->fresh()->team_id)->toBeNull();
})->with(['E0123ABC', 'not a workspace', 'T']);

test('with nothing unbound the command says so', function () {
    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC'])
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();
});

test('without --force and without interaction the command fails and changes nothing', function () {
    $identity = unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC', '--no-interaction' => true])
        ->expectsOutputToContain('pass --force')
        ->assertFailed();

    expect($identity->fresh()->team_id)->toBeNull();
});

test('a workspace the app is not installed in is flagged before the question', function () {
    SlackApp::install(Team::factory()->create());
    unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC'])
        ->expectsOutputToContain('is not a workspace the app is installed in')
        ->expectsConfirmation('Bind 1 Slack identity without a workspace to T0123ABC? Those Slack members will be able to act as their QueryProxy users from that workspace.', 'no')
        ->assertFailed();
});

test('an unknown workspace is flagged with --force too, and the identities are still bound', function () {
    SlackApp::install(Team::factory()->create());
    $identity = unboundSlackIdentity('U_ONE');

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC', '--force' => true])
        ->expectsOutputToContain('is not a workspace the app is installed in')
        ->assertSuccessful();

    expect($identity->fresh()->team_id)->toBe('T0123ABC');
});

test('identities that already have a workspace are never moved', function () {
    $bound = ChatIdentity::create(['user_id' => User::factory()->create()->id, 'provider' => 'slack', 'external_id' => 'U_BOUND', 'team_id' => 'T_KEEP']);

    $this->artisan('queryproxy:chat:bind-slack-workspace', ['team_id' => 'T0123ABC', '--force' => true])->assertSuccessful();

    expect($bound->fresh()->team_id)->toBe('T_KEEP');
});
