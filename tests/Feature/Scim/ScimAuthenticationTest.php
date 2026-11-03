<?php

use App\Models\AuditLog;
use App\Models\ScimToken;
use App\Models\User;
use App\Services\Api\TokenService;
use App\Services\Scim\ScimTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ScimWorld;

test('SCIM answers 404 until it is switched on', function () {
    config(['queryproxy.scim.enabled' => false]);
    [, $token] = app(ScimTokenService::class)->create('idp');

    $this->call('GET', '/scim/v2/Users', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token])
        ->assertNotFound()
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)
        ->assertJsonPath('status', '404');

    $this->call('GET', '/scim/v2/ServiceProviderConfig')->assertNotFound();
});

test('a missing, wrong, revoked or personal access token is 401 with a SCIM error body', function () {
    $world = ScimWorld::make();
    $user = User::factory()->create();
    $pat = app(TokenService::class)->create($user, 'pat', TokenService::scopes(), 30)->plainTextToken;

    [$revoked, $revokedValue] = app(ScimTokenService::class)->create('old');
    app(ScimTokenService::class)->revoke($revoked);

    $this->getJson('/scim/v2/Users')->assertUnauthorized()
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)
        ->assertJsonPath('status', '401')
        ->assertJsonStructure(['detail'])
        ->assertHeader('Content-Type', 'application/scim+json')
        ->assertHeader('WWW-Authenticate', 'Bearer realm="scim"');

    foreach (['not-a-token', $pat, $revokedValue] as $bad) {
        $world->call('GET', '/Users', null, $bad)->assertUnauthorized()->assertJsonPath('schemas.0', ScimWorld::ERROR);
    }

    // And the other way round: a SCIM token is no API credential.
    $this->withToken($world->token)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a valid token works and the plaintext is never stored', function () {
    $world = ScimWorld::make();

    $world->call('GET', '/Users')->assertOk()
        ->assertHeader('Content-Type', 'application/scim+json')
        ->assertJsonPath('totalResults', 0);

    $row = ScimToken::query()->first();

    expect($row->token_hash)->toBe(hash('sha256', $world->token))
        ->and(DB::table('scim_tokens')->get()->toJson())->not->toContain($world->token)
        ->and($row->last_used_at)->not->toBeNull();
});

test('failed authentications from one address are throttled, working ones are not', function () {
    $world = ScimWorld::make();
    config(['queryproxy.scim.rate_limit_per_minute' => 3]);
    RateLimiter::clear('scim-auth-failures:127.0.0.1');

    foreach (range(1, 3) as $ignored) {
        $world->call('GET', '/Users', null, 'wrong')->assertUnauthorized();
    }

    $world->call('GET', '/Users', null, 'wrong')
        ->assertStatus(429)
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)
        ->assertHeader('Retry-After');

    $world->call('GET', '/Users')->assertOk();
});

test('a token has a request budget of its own', function () {
    $world = ScimWorld::make();
    config(['queryproxy.scim.rate_limit_per_minute' => 2]);

    $world->call('GET', '/Users')->assertOk();
    $world->call('GET', '/Users')->assertOk();
    $world->call('GET', '/Users')->assertStatus(429)->assertJsonPath('schemas.0', ScimWorld::ERROR);
});

test('the discovery endpoints describe the server', function () {
    $world = ScimWorld::make();

    $world->call('GET', '/ServiceProviderConfig')->assertOk()
        ->assertJsonPath('patch.supported', true)
        ->assertJsonPath('bulk.supported', false)
        ->assertJsonPath('filter.supported', true)
        ->assertJsonPath('authenticationSchemes.0.type', 'oauthbearertoken');

    $world->call('GET', '/ResourceTypes')->assertOk()->assertJsonPath('totalResults', 2);
    $world->call('GET', '/ResourceTypes/User')->assertOk()->assertJsonPath('endpoint', '/Users');
    $world->call('GET', '/Schemas')->assertOk()->assertJsonPath('totalResults', 2);
    $world->call('GET', '/Schemas/'.ScimWorld::GROUP)->assertOk()->assertJsonPath('name', 'Group');
    $world->call('GET', '/Schemas/nope')->assertNotFound()->assertJsonPath('schemas.0', ScimWorld::ERROR);
});

test('unknown paths and methods get SCIM errors too', function () {
    $world = ScimWorld::make();

    $world->call('GET', '/Nothing')->assertNotFound()->assertJsonPath('schemas.0', ScimWorld::ERROR);
    $world->call('DELETE', '/ServiceProviderConfig')->assertStatus(405)->assertJsonPath('schemas.0', ScimWorld::ERROR);
});

test('creating and revoking a token is audited without the value', function () {
    $world = ScimWorld::make();
    $token = ScimToken::query()->first();

    app(ScimTokenService::class)->revoke($token);
    app(ScimTokenService::class)->revoke($token);

    expect(AuditLog::where('action', 'scim.token_created')->count())->toBe(1)
        ->and(AuditLog::where('action', 'scim.token_revoked')->count())->toBe(1)
        ->and(AuditLog::all()->toJson())->not->toContain($world->token);
});
