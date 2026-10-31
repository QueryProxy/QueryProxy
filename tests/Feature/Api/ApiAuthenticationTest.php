<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\ApiWorld;

beforeEach(function () {
    Notification::fake();
});

test('a call without a token is unauthenticated', function () {
    $this->getJson('/api/v1/connections')->assertUnauthorized();
    $this->postJson('/api/v1/requests', [])->assertUnauthorized();
});

test('a garbage token is unauthenticated', function () {
    ApiWorld::as('999|not-a-real-token')->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a session cookie does not authenticate an API call', function () {
    $world = ApiWorld::make();

    $this->actingAs($world->dba)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a token outside its scope gets 403 on every route', function () {
    $world = ApiWorld::make();
    $request = $world->pendingRequest($world->developer);

    $readOnly = ApiWorld::token($world->dba, ['requests:read']);
    $submitOnly = ApiWorld::token($world->dba, ['requests:submit']);
    $approveOnly = ApiWorld::token($world->dba, ['approvals:write']);

    ApiWorld::as($readOnly)->postJson('/api/v1/requests', ['connection_id' => $world->connection->id, 'sql' => 'SELECT 1'])->assertForbidden();
    ApiWorld::as($submitOnly)->getJson("/api/v1/requests/{$request->id}")->assertForbidden();
    ApiWorld::as($readOnly)->getJson("/api/v1/requests/{$request->id}/result")->assertForbidden();
    ApiWorld::as($readOnly)->postJson("/api/v1/requests/{$request->id}/approve")->assertForbidden();
    ApiWorld::as($readOnly)->postJson("/api/v1/requests/{$request->id}/reject", ['reason' => 'no'])->assertForbidden();
    ApiWorld::as($approveOnly)->postJson("/api/v1/requests/{$request->id}/cancel")->assertForbidden();
    ApiWorld::as($approveOnly)->getJson('/api/v1/connections')->assertForbidden();

    expect($request->fresh()->isPending())->toBeTrue();
});

test('an expired token gets 401', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->dba);

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertOk();

    PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a token lasts no longer than the configured maximum even if its own expiry is later', function () {
    config(['sanctum.expiration' => 60]);

    $world = ApiWorld::make();
    $token = ApiWorld::token($world->dba);

    PersonalAccessToken::query()->update(['created_at' => now()->subMinutes(61)]);

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a token without an expiry is refused', function () {
    $world = ApiWorld::make();
    $token = $world->dba->createToken('no expiry', ['requests:read'])->plainTextToken;

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('suspending a user revokes their tokens for good: lifting the suspension does not bring them back', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->dba);

    $world->dba->forceFill(['suspended_at' => now()])->save();

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
    expect(PersonalAccessToken::count())->toBe(0);

    $world->dba->forceFill(['suspended_at' => null])->save();

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a token of a suspended user is refused even when the suspension did not go through the model', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->dba);

    User::whereKey($world->dba->id)->update(['suspended_at' => now()]);

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('the token of a deleted user gets 401', function () {
    $world = ApiWorld::make();
    $token = ApiWorld::token($world->dba);

    $world->dba->delete();

    ApiWorld::as($token)->getJson('/api/v1/connections')->assertUnauthorized();
    expect(PersonalAccessToken::count())->toBe(0);
});

test('a token carries the qp_ prefix so that leaked ones can be recognised', function () {
    $token = ApiWorld::token(ApiWorld::make()->dba);

    expect(explode('|', $token, 2)[1])->toStartWith('qp_');
});

test('calls that fail to authenticate are throttled per address, while valid tokens on that address keep working', function () {
    config(['queryproxy.api.rate_limit_per_minute' => 3]);

    $world = ApiWorld::make();
    $valid = ApiWorld::token($world->dba);

    foreach (range(1, 3) as $ignored) {
        ApiWorld::as('1|wrong')->getJson('/api/v1/connections')->assertUnauthorized();
    }

    // The address is now over its budget of failures.
    ApiWorld::as('1|wrong')->getJson('/api/v1/connections')->assertStatus(429)->assertHeader('Retry-After');
    $this->getJson('/api/v1/connections')->assertStatus(429);

    // A valid token from the same address is not affected, and is not counted.
    foreach (range(1, 3) as $ignored) {
        ApiWorld::as($valid)->getJson('/api/v1/connections')->assertOk();
    }

    ApiWorld::as('1|wrong')->getJson('/api/v1/connections')->assertStatus(429);
});

test('each token has its own rate limit and exceeding it gets 429', function () {
    config(['queryproxy.api.rate_limit_per_minute' => 3]);

    $world = ApiWorld::make();
    $first = ApiWorld::token($world->dba);
    $second = ApiWorld::token($world->dba);

    foreach (range(1, 3) as $ignored) {
        ApiWorld::as($first)->getJson('/api/v1/connections')->assertOk()->assertHeader('X-RateLimit-Limit', '3');
    }

    ApiWorld::as($first)->getJson('/api/v1/connections')->assertStatus(429)->assertHeader('Retry-After');
    ApiWorld::as($second)->getJson('/api/v1/connections')->assertOk();
});
