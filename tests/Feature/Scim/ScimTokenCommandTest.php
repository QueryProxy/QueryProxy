<?php

use App\Models\AuditLog;
use App\Models\ScimToken;
use App\Models\User;

test('create prints the token once and stores only its hash', function () {
    $this->artisan('queryproxy:scim-token', ['action' => 'create', '--name' => 'entra'])
        ->expectsOutputToContain('qpscim_')
        ->assertSuccessful();

    $row = ScimToken::sole();

    expect($row->name)->toBe('entra')
        ->and($row->token_hash)->toHaveLength(64)
        ->and($row->token_hash)->not->toStartWith('qpscim_')
        ->and(AuditLog::where('action', 'scim.token_created')->count())->toBe(1);
});

test('a token can be revoked, and revoked twice is not an error', function () {
    $this->artisan('queryproxy:scim-token', ['action' => 'create', '--name' => 'okta'])->assertSuccessful();
    $id = ScimToken::sole()->id;

    $this->artisan('queryproxy:scim-token', ['action' => 'revoke', '--id' => $id])->assertSuccessful();
    expect(ScimToken::sole()->isRevoked())->toBeTrue();

    $this->artisan('queryproxy:scim-token', ['action' => 'revoke', '--id' => $id])->assertSuccessful();
    expect(AuditLog::where('action', 'scim.token_revoked')->count())->toBe(1);
});

test('list shows the tokens and never a value', function () {
    $this->artisan('queryproxy:scim-token', ['action' => 'create', '--name' => 'keycloak'])->assertSuccessful();

    $this->artisan('queryproxy:scim-token', ['action' => 'list'])
        ->expectsOutputToContain('keycloak')
        ->doesntExpectOutputToContain('qpscim_')
        ->assertSuccessful();
});

test('bad input is refused', function () {
    $this->artisan('queryproxy:scim-token', ['action' => 'create'])->assertFailed();
    $this->artisan('queryproxy:scim-token', ['action' => 'revoke', '--id' => 12345])->assertFailed();
    $this->artisan('queryproxy:scim-token', ['action' => 'explode'])->assertFailed();
    $this->artisan('queryproxy:scim-token', ['action' => 'create', '--name' => 'x', '--by' => 'nobody@example.test'])->assertFailed();

    expect(ScimToken::count())->toBe(0);
});

test('the administrator named with --by is the actor in the audit log', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->artisan('queryproxy:scim-token', ['action' => 'create', '--name' => 'idp', '--by' => $admin->email])->assertSuccessful();

    expect(AuditLog::where('action', 'scim.token_created')->sole()->user_id)->toBe($admin->id)
        ->and(ScimToken::sole()->created_by)->toBe($admin->id);
});
