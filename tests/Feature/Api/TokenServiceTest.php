<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Api\TokenService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\ApiWorld;

test('a token is created with its scopes and an expiry, and the creation is audited without the token value', function () {
    $user = User::factory()->create();

    $token = app(TokenService::class)->create($user, '  ci pipeline ', ['requests:submit', 'requests:read', 'requests:read'], 30);

    $stored = PersonalAccessToken::findOrFail($token->accessToken->id);

    expect($stored->name)->toBe('ci pipeline')
        ->and($stored->abilities)->toBe(['requests:submit', 'requests:read'])
        ->and($stored->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($stored->tokenable_id)->toBe($user->id);

    $log = AuditLog::where('action', AuditEvent::TokenCreated->value)->sole();

    expect($log->user_id)->toBe($user->id)
        ->and($log->metadata['token_id'])->toBe($stored->id)
        ->and($log->metadata['name'])->toBe('ci pipeline')
        ->and($log->metadata['scopes'])->toBe(['requests:submit', 'requests:read'])
        ->and($log->metadata)->toHaveKey('expires_at')
        ->and(json_encode($log->metadata))->not->toContain(explode('|', $token->plainTextToken)[1])
        ->and(json_encode($log->getAttributes()))->not->toContain(explode('|', $token->plainTextToken)[1]);
});

test('the token value is stored only as a hash', function () {
    $token = app(TokenService::class)->create(User::factory()->create(), 'x', ['requests:read'], 1);

    $stored = PersonalAccessToken::findOrFail($token->accessToken->id);

    expect($stored->token)->not->toBe(explode('|', $token->plainTextToken)[1])
        ->and($stored->token)->toBe(hash('sha256', explode('|', $token->plainTextToken)[1]));
});

test('a lifetime beyond the configured maximum, or none, is refused', function (int $days) {
    config(['queryproxy.api.token_max_days' => 90]);

    app(TokenService::class)->create(User::factory()->create(), 'x', ['requests:read'], $days);
})->with([0, -1, 91])->throws(ValidationException::class);

test('the lifetime limit follows QUERYPROXY_API_TOKEN_MAX_DAYS', function () {
    config(['queryproxy.api.token_max_days' => 7]);

    $user = User::factory()->create();

    expect(app(TokenService::class)->create($user, 'x', ['requests:read'], 7)->accessToken->expires_at)->not->toBeNull();

    app(TokenService::class)->create($user, 'x', ['requests:read'], 8);
})->throws(ValidationException::class);

test('unknown or missing scopes and a bad name are refused', function (string $name, array $scopes) {
    app(TokenService::class)->create(User::factory()->create(), $name, $scopes, 10);
})->with([
    'no scopes' => ['x', []],
    'wildcard' => ['x', ['*']],
    'unknown scope' => ['x', ['requests:read', 'admin:everything']],
    'empty name' => ['   ', ['requests:read']],
    'long name' => [str_repeat('a', 101), ['requests:read']],
])->throws(ValidationException::class);

test('a suspended user cannot create a token', function () {
    $user = User::factory()->create();
    $user->forceFill(['suspended_at' => now()])->save();

    app(TokenService::class)->create($user, 'x', ['requests:read'], 10);
})->throws(AuthorizationException::class);

test('a token is revoked by its owner and the revocation is audited', function () {
    $user = User::factory()->create();
    $token = app(TokenService::class)->create($user, 'to revoke', ['requests:read'], 10);

    app(TokenService::class)->revoke($user, $token->accessToken->id);

    expect(PersonalAccessToken::find($token->accessToken->id))->toBeNull();

    $log = AuditLog::where('action', AuditEvent::TokenRevoked->value)->sole();

    expect($log->user_id)->toBe($user->id)
        ->and($log->metadata)->toBe(['token_id' => $token->accessToken->id, 'name' => 'to revoke']);
});

test('a token of another user cannot be revoked', function () {
    $owner = User::factory()->create();
    $token = app(TokenService::class)->create($owner, 'mine', ['requests:read'], 10);

    app(TokenService::class)->revoke(User::factory()->create(), $token->accessToken->id);
})->throws(ModelNotFoundException::class);

test('a revoked token no longer authenticates', function () {
    $user = User::factory()->create();
    $token = app(TokenService::class)->create($user, 'x', ['requests:read'], 10);

    app(TokenService::class)->revoke($user, $token->accessToken->id);

    ApiWorld::as($token->plainTextToken)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('revoking all tokens of an owner records why, for each token', function () {
    $user = User::factory()->create();
    $first = app(TokenService::class)->create($user, 'one', ['requests:read'], 10);
    app(TokenService::class)->create($user, 'two', ['requests:read'], 10);
    app(TokenService::class)->create(User::factory()->create(), 'other', ['requests:read'], 10);

    expect(app(TokenService::class)->revokeAll($user, 'user_suspended'))->toBe(2)
        ->and(PersonalAccessToken::count())->toBe(1);

    $log = AuditLog::where('action', AuditEvent::TokenRevoked->value)->where('metadata->token_id', $first->accessToken->id)->sole();

    expect($log->metadata)->toBe(['token_id' => $first->accessToken->id, 'name' => 'one', 'owner_id' => $user->id, 'reason' => 'user_suspended']);
});
