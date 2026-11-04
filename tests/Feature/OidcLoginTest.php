<?php

/*
 * OIDC single sign-on (ADR-023) against a fake identity provider: discovery,
 * JWKS, token and userinfo endpoints are answered by Http::fake() and ID tokens
 * are signed with a key generated here. The real thing (Keycloak) is covered
 * by tests/Integration/Oidc/KeycloakLoginTest.php.
 */

use App\Http\Controllers\Auth\OidcController;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OidcRoleSync;
use Firebase\JWT\JWT;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

/** The fake identity provider: what it signs with and what it answers. */
final class OidcFakeProvider
{
    public const ISSUER = 'https://idp.test/realms/qp';

    public const CLIENT_ID = 'queryproxy';

    public const CLIENT_SECRET = 'fake-client-secret';

    /** @var array<string, array{kid: string, pem: string, jwk: array<string, string>}> */
    private static array $keys = [];

    /** @var array<string, mixed> claims that replace or add to the defaults; a null value removes one */
    public static array $claims = [];

    /** kid of the key the next token is signed with */
    public static string $signWith = 'key-1';

    /** @var array<string, mixed>|null body of the userinfo endpoint, null = endpoint not advertised */
    public static ?array $userInfo = null;

    /** When set, the token endpoint returns this instead of a properly signed token. */
    public static ?string $forcedIdToken = null;

    /** @var string|null the end_session_endpoint the discovery document advertises, null = none */
    public static ?string $endSession = null;

    /** The issuer the discovery document claims. */
    public static string $documentIssuer = self::ISSUER;

    /** Nonce the provider echoes; set from the redirect URL by oidcStartLogin(). */
    public static string $nonce = '';

    /** @var list<string> kids published in the JWKS */
    public static array $published = ['key-1'];

    public static function reset(): void
    {
        self::$claims = [];
        self::$signWith = 'key-1';
        self::$userInfo = null;
        self::$nonce = '';
        self::$published = ['key-1'];
        self::$forcedIdToken = null;
        self::$endSession = null;
        self::$documentIssuer = self::ISSUER;
    }

    /** @return array{kid: string, pem: string, jwk: array<string, string>} */
    public static function key(string $kid): array
    {
        return self::$keys[$kid] ??= (function () use ($kid) {
            $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            $details = openssl_pkey_get_details($resource);
            openssl_pkey_export($resource, $pem);

            $encode = fn (string $binary) => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

            return [
                'kid' => $kid,
                'pem' => $pem,
                'jwk' => [
                    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
                    'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
                ],
            ];
        })();
    }

    /** @return array<string, mixed> */
    public static function defaultClaims(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'sub-alice',
            'aud' => self::CLIENT_ID,
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => self::$nonce,
            'email' => 'alice@example.test',
            'email_verified' => true,
            'name' => 'Alice Example',
            'groups' => ['platform-dba'],
        ];
    }

    public static function idToken(): string
    {
        $claims = array_filter(
            array_merge(self::defaultClaims(), self::$claims),
            fn ($value) => $value !== null,
        );

        $key = self::key(self::$signWith);

        return JWT::encode($claims, $key['pem'], 'RS256', $key['kid']);
    }

    /** @return array<string, mixed> */
    public static function discovery(): array
    {
        return array_filter([
            'issuer' => self::$documentIssuer,
            'authorization_endpoint' => 'https://idp.test/auth',
            'token_endpoint' => 'https://idp.test/token',
            'jwks_uri' => 'https://idp.test/jwks',
            'end_session_endpoint' => self::$endSession,
            'userinfo_endpoint' => self::$userInfo !== null ? 'https://idp.test/userinfo' : null,
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ]);
    }

    public static function fake(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                self::ISSUER.'/.well-known/openid-configuration' => Http::response(self::discovery()),
                'https://idp.test/jwks' => Http::response(['keys' => array_map(fn (string $kid) => self::key($kid)['jwk'], self::$published)]),
                'https://idp.test/token' => Http::response(['id_token' => self::$forcedIdToken ?? self::idToken(), 'access_token' => 'access-token', 'token_type' => 'Bearer']),
                'https://idp.test/userinfo' => Http::response(array_merge(['sub' => 'sub-alice'], self::$userInfo ?? [])),
                default => Http::response('', 404),
            };
        });
    }
}

beforeEach(function () {
    OidcFakeProvider::reset();
    OidcFakeProvider::fake();

    config([
        'queryproxy.oidc' => [
            'issuer' => OidcFakeProvider::ISSUER,
            'client_id' => OidcFakeProvider::CLIENT_ID,
            'client_secret' => OidcFakeProvider::CLIENT_SECRET,
            'group_claim' => 'groups',
            'role_map' => 'platform-dba:payments:dba,platform-dev:payments:developer,auditors:billing:auditor',
            'scopes' => 'openid email profile',
        ],
        'queryproxy.local_login' => 'all',
        'queryproxy.require_two_factor' => 'none',
    ]);
});

/** Starts a sign-in the way the button does and returns the query of the provider URL. */
function oidcStartLogin(): array
{
    $location = test()->get(route('oidc.redirect'))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    OidcFakeProvider::$nonce = $query['nonce'];

    return $query;
}

/** A complete sign-in: redirect, then the provider's callback with a valid state. */
function oidcLogin(array $claims = [], ?string $state = null): TestResponse
{
    OidcFakeProvider::$claims = $claims;
    $query = oidcStartLogin();

    return test()->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $state ?? $query['state']]));
}

function oidcTeams(): array
{
    return [
        Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']),
        Team::factory()->create(['name' => 'Billing', 'slug' => 'billing']),
        Team::factory()->create(['name' => 'Hands', 'slug' => 'hands']),
    ];
}

function oidcAuditActions(): array
{
    return AuditLog::orderBy('id')->pluck('action')->all();
}

function oidcTokenRequests(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === 'https://idp.test/token')->count();
}

test('the redirect sends the browser to the provider with state, nonce and an S256 PKCE challenge', function () {
    $query = oidcStartLogin();

    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'queryproxy',
        'redirect_uri' => route('oidc.callback'),
        'scope' => 'openid email profile',
        'code_challenge_method' => 'S256',
    ])
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32)
        ->and(strlen($query['nonce']))->toBeGreaterThanOrEqual(32)
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/');

    $flow = session('oidc.flow');
    expect($flow['state'])->toBe($query['state'])
        ->and(rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='))->toBe($query['code_challenge']);
});

test('the code exchange sends the verifier and authenticates the client with the secret', function () {
    oidcLogin();

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://idp.test/token') {
            return false;
        }

        return $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request['redirect_uri'] === route('oidc.callback')
            && strlen((string) $request['code_verifier']) >= 43
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('queryproxy:'.OidcFakeProvider::CLIENT_SECRET);
    });

    // The attempt is consumed by the callback.
    expect(session()->has('oidc.flow'))->toBeFalse();
});

test('the first sign-in creates the user, grants the mapped role and is audited', function () {
    [$payments] = oidcTeams();

    oidcLogin()->assertRedirect(route('dashboard'));

    $user = User::where('email', 'alice@example.test')->firstOrFail();

    expect(auth()->id())->toBe($user->id)
        ->and($user->auth_provider)->toBe('oidc')
        ->and($user->external_id)->toBe('sub-alice')
        ->and($user->password)->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->name)->toBe('Alice Example')
        ->and($user->roleIn($payments)->value)->toBe('dba')
        ->and(oidcAuditActions())->toBe(['user.sso_provisioned', 'user.role_synced', 'auth.sso_login']);

    $log = AuditLog::where('action', 'user.role_synced')->firstOrFail();
    expect($log->team_id)->toBe($payments->id)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->metadata)->toMatchArray(['change' => 'added', 'team' => 'payments', 'from' => null, 'to' => 'dba']);
});

test('no token, code or secret reaches the audit log', function () {
    oidcTeams();
    oidcLogin();

    $dump = json_encode(AuditLog::all()->toArray());

    expect($dump)->not->toContain('access-token')
        ->and($dump)->not->toContain('auth-code')
        ->and($dump)->not->toContain(OidcFakeProvider::CLIENT_SECRET)
        ->and($dump)->not->toContain('eyJ');
});

test('the team role follows the groups at every login: changed, then removed', function () {
    [$payments, $billing, $hands] = oidcTeams();
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null]);
    $hands->users()->attach($user, ['role' => 'developer']); // by hand, team not in the map
    $billing->users()->attach($user, ['role' => 'developer']); // in the map, no matching group

    // Group platform-dba: payments -> dba; billing is managed and the user is in no auditors group -> removed.
    oidcLogin();
    auth()->logout();

    expect($user->fresh()->roleIn($payments)->value)->toBe('dba')
        ->and($user->fresh()->roleIn($billing))->toBeNull()
        ->and($user->fresh()->roleIn($hands)->value)->toBe('developer');

    // Moved from the DBA to the developer group.
    oidcLogin(['groups' => ['platform-dev', 'auditors']]);
    auth()->logout();

    expect($user->fresh()->roleIn($payments)->value)->toBe('developer')
        ->and($user->fresh()->roleIn($billing)->value)->toBe('auditor');

    // Out of every group: both mapped memberships go, the manual one stays.
    oidcLogin(['groups' => []]);

    expect($user->fresh()->roleIn($payments))->toBeNull()
        ->and($user->fresh()->roleIn($billing))->toBeNull()
        ->and($user->fresh()->roleIn($hands)->value)->toBe('developer');

    $changes = AuditLog::where('action', 'user.role_synced')->orderBy('id')->get()
        ->map(fn ($log) => $log->metadata['team'].':'.$log->metadata['change'].':'.($log->metadata['from'] ?? '-').'>'.($log->metadata['to'] ?? '-'))
        ->all();

    expect($changes)->toHaveCount(6)
        ->and($changes)->toContain('billing:removed:developer>-')
        ->and($changes)->toContain('payments:added:->dba')
        ->and($changes)->toContain('payments:changed:dba>developer')
        ->and($changes)->toContain('billing:added:->auditor')
        ->and($changes)->toContain('payments:removed:developer>-')
        ->and($changes)->toContain('billing:removed:auditor>-');
});

test('when two groups reach the same team the higher role wins', function () {
    [$payments] = oidcTeams();

    oidcLogin(['groups' => ['platform-dev', 'platform-dba']]);

    expect(User::where('email', 'alice@example.test')->firstOrFail()->roleIn($payments)->value)->toBe('dba');
});

test('a login without the group claim is refused while a role map is set, and nothing is created', function () {
    oidcTeams();
    Log::spy();

    oidcLogin(['groups' => null])->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcAuditActions())->toBe([]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'no group claim'))->once();
});

test('a login without the group claim leaves the memberships of an existing user alone', function () {
    [$payments, $billing, $hands] = oidcTeams();
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null]);
    $payments->users()->attach($user, ['role' => 'dba']);
    $billing->users()->attach($user, ['role' => 'auditor']);
    $hands->users()->attach($user, ['role' => 'developer']);

    oidcLogin(['groups' => null])->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()->roleIn($payments)->value)->toBe('dba')
        ->and($user->fresh()->roleIn($billing)->value)->toBe('auditor')
        ->and($user->fresh()->roleIn($hands)->value)->toBe('developer')
        ->and(oidcAuditActions())->toBe([]);
});

test('a groups overage (a reference instead of the list) is refused like a missing claim', function () {
    oidcTeams();
    Log::spy();

    oidcLogin(['groups' => null, 'hasgroups' => true, '_claim_names' => ['groups' => 'src1']])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'no group claim') && $context['groups_overage'] === true)->once();
});

test('an empty group list is a statement: the mapped roles are revoked and the login goes through', function () {
    [$payments] = oidcTeams();
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null]);
    $payments->users()->attach($user, ['role' => 'dba']);

    oidcLogin(['groups' => []])->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id)
        ->and($user->fresh()->roleIn($payments))->toBeNull()
        ->and(oidcAuditActions())->toBe(['user.role_synced', 'auth.sso_login']);
});

test('without a role map a missing group claim is no obstacle', function () {
    config(['queryproxy.oidc.role_map' => '']);

    oidcLogin(['groups' => null])->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

test('the userinfo endpoint supplies the groups when the ID token has none', function () {
    [$payments] = oidcTeams();
    OidcFakeProvider::$userInfo = ['groups' => ['platform-dba']];

    oidcLogin(['groups' => null]);

    expect(User::where('email', 'alice@example.test')->firstOrFail()->roleIn($payments)->value)->toBe('dba');
});

test('userinfo with another subject is ignored, so the groups stay missing and the login is refused', function () {
    oidcTeams();
    OidcFakeProvider::$userInfo = ['sub' => 'someone-else', 'groups' => ['platform-dba']];

    oidcLogin(['groups' => null])->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
});

test('the group claim can be a dotted path and a single string', function () {
    [$payments] = oidcTeams();
    config(['queryproxy.oidc.group_claim' => 'realm_access.roles']);

    oidcLogin(['groups' => null, 'realm_access' => ['roles' => 'platform-dba']]);

    expect(User::where('email', 'alice@example.test')->firstOrFail()->roleIn($payments)->value)->toBe('dba');
});

test('an invalid state is refused before the provider is asked for anything', function () {
    oidcLogin(state: 'not-the-state')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcTokenRequests())->toBe(0);
});

test('a callback without a sign-in attempt in progress is refused', function () {
    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'x']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse();
});

test('the attempt is single use: replaying the callback fails', function () {
    $query = oidcStartLogin();
    $url = route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]);

    $this->get($url)->assertRedirect(route('dashboard'));
    auth()->logout();
    $this->post('/logout');

    $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('sso');
    expect(auth()->check())->toBeFalse();
});

test('an ID token with the wrong nonce is refused', function () {
    oidcLogin(['nonce' => 'a-different-nonce'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
});

test('an ID token without a nonce is refused', function () {
    oidcLogin(['nonce' => null])->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse();
});

test('ID tokens that fail validation are refused', function (array $claims, string $signWith) {
    OidcFakeProvider::key('rogue');
    OidcFakeProvider::$signWith = $signWith;

    oidcLogin($claims)->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
})->with([
    'signed by a key the provider does not publish' => [[], 'rogue'],
    'another issuer' => [['iss' => 'https://evil.test'], 'key-1'],
    'another audience' => [['aud' => 'someone-elses-client'], 'key-1'],
    'several audiences without azp' => [['aud' => ['queryproxy', 'other']], 'key-1'],
    'azp of another client' => [['aud' => ['queryproxy', 'other'], 'azp' => 'other'], 'key-1'],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'key-1'],
    'issued in the future' => [['iat' => time() + 3600, 'exp' => time() + 7200], 'key-1'],
    'no expiry' => [['exp' => null], 'key-1'],
    'no subject' => [['sub' => null], 'key-1'],
]);

test('an unsigned token (alg none) is refused', function () {
    $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    $query = oidcStartLogin();

    OidcFakeProvider::$forcedIdToken = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode(array_merge(OidcFakeProvider::defaultClaims(), ['nonce' => $query['nonce']])).'.';

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
});

test('a token HMAC-signed with the public key is refused', function () {
    $query = oidcStartLogin();
    $jwk = OidcFakeProvider::key('key-1')['jwk'];

    // The classic algorithm confusion: HS256 with the published modulus as the secret.
    OidcFakeProvider::$forcedIdToken = JWT::encode(array_merge(OidcFakeProvider::defaultClaims(), ['nonce' => $query['nonce']]), str_repeat($jwk['n'], 2), 'HS256', 'key-1');

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse();
});

test('a rotated signing key is picked up by refreshing the cached key set once', function () {
    // The cached set still holds only the old key; the provider now signs with key-2.
    Cache::put('queryproxy.oidc.jwks.'.sha1('https://idp.test/jwks'), ['keys' => [OidcFakeProvider::key('key-1')['jwk']]]);
    OidcFakeProvider::$published = ['key-1', 'key-2'];
    OidcFakeProvider::$signWith = 'key-2';

    oidcLogin()->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

test('the provider returning an error is refused', function () {
    oidcStartLogin();

    $this->get(route('oidc.callback', ['error' => 'access_denied', 'state' => session('oidc.flow')['state']]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(oidcTokenRequests())->toBe(0);
});

test('a suspended SSO user cannot sign in and keeps their roles untouched', function () {
    [$payments] = oidcTeams();
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null, 'suspended_at' => now()]);

    oidcLogin()->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => 'This account is suspended.']);

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()->roleIn($payments))->toBeNull()
        ->and(oidcAuditActions())->toBe([]);
});

test('a deleted SSO user cannot sign in and is not created again', function () {
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null]);
    $user->delete();

    oidcLogin()->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::withTrashed()->count())->toBe(1);
});

test('a deleted local account with the same e-mail is not revived by SSO', function () {
    $user = User::factory()->create(['email' => 'alice@example.test']);
    $user->delete();

    oidcLogin()->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()?->auth_provider ?? User::withTrashed()->find($user->id)->auth_provider)->toBe('local');
});

test('a local account is linked when the provider asserts the e-mail as verified', function () {
    [$payments] = oidcTeams();
    $local = User::factory()->create(['email' => 'Alice@Example.test', 'password' => 'secret-password', 'is_admin' => true]);

    oidcLogin()->assertRedirect(route('dashboard'));

    $local->refresh();
    expect(auth()->id())->toBe($local->id)
        ->and($local->auth_provider)->toBe('oidc')
        ->and($local->external_id)->toBe('sub-alice')
        ->and($local->is_admin)->toBeTrue()
        ->and($local->roleIn($payments)->value)->toBe('dba')
        ->and(User::count())->toBe(1)
        ->and(oidcAuditActions())->toBe(['user.sso_linked', 'user.role_synced', 'auth.sso_login']);

    expect(AuditLog::where('action', 'user.sso_linked')->first()->metadata)->toMatchArray(['user' => 'Alice@Example.test', 'issuer' => OidcFakeProvider::ISSUER]);
});

test('a local account is not linked when the provider does not assert the e-mail as verified', function (array $claims) {
    $local = User::factory()->create(['email' => 'alice@example.test', 'password' => 'secret-password']);

    oidcLogin($claims)->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and($local->fresh()->auth_provider)->toBe('local')
        ->and($local->fresh()->external_id)->toBeNull()
        ->and(User::count())->toBe(1)
        ->and(oidcAuditActions())->toBe(['auth.sso_refused']);

    expect(AuditLog::first()->metadata)->toMatchArray(['reason' => 'link_unverified_email', 'user' => 'alice@example.test', 'issuer' => OidcFakeProvider::ISSUER]);
})->with([
    'email_verified false' => [['email_verified' => false]],
    'email_verified missing' => [['email_verified' => null]],
    'email_verified a string other than true' => [['email_verified' => 'yes']],
]);

test('an account tied to another provider subject is not taken over by e-mail', function () {
    $user = User::factory()->create(['email' => 'alice@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'the-real-alice', 'password' => null]);

    oidcLogin(['sub' => 'an-impostor'])->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and($user->fresh()->external_id)->toBe('the-real-alice');
});

test('a token without a usable e-mail cannot provision a user', function () {
    oidcLogin(['email' => null])->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
});

test('a returning user is found by subject even when the e-mail changed at the provider', function () {
    $user = User::factory()->create(['email' => 'old@example.test', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice', 'password' => null]);

    oidcLogin(['email' => 'new@example.test'])->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id)->and(User::count())->toBe(1);
});

test('SSO is a 404 and the login screen shows no button when it is not configured', function () {
    config(['queryproxy.oidc.issuer' => '', 'queryproxy.oidc.client_id' => '']);

    $this->get(route('oidc.redirect'))->assertNotFound();
    $this->get(route('oidc.callback'))->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee('Log in with SSO');
});

test('the login screen offers the SSO button when it is configured', function () {
    $this->get('/login')->assertOk()->assertSee('Log in with SSO')->assertSee(route('oidc.redirect'), false);
});

test('a discovery document for another issuer is refused', function () {
    OidcFakeProvider::$documentIssuer = 'https://evil.test';

    $this->get(route('oidc.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('sso');
});

test('local_login none refuses the password form and hides it', function () {
    config(['queryproxy.local_login' => 'none']);
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();

    $this->get('/login')->assertOk()->assertDontSee('Forgot password?')->assertSee('Log in with SSO');
});

test('local_login none switches password reset off', function () {
    config(['queryproxy.local_login' => 'none']);
    Notification::fake();
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->get('/forgot-password')->assertRedirect(route('login'));
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $token = Password::createToken($user);
    $this->get('/reset-password/'.$token)->assertRedirect(route('login'));
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertRedirect(route('login'));

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeFalse();
});

test('local_login admins_only lets only system admins in with a password', function () {
    config(['queryproxy.local_login' => 'admins_only']);
    $admin = User::factory()->create(['password' => 'secret-password', 'is_admin' => true]);
    $member = User::factory()->create(['password' => 'secret-password']);

    $this->post('/login', ['email' => $member->email, 'password' => 'secret-password'])->assertSessionHasErrors(['email' => __('auth.failed')]);
    expect(auth()->check())->toBeFalse();

    $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password'])->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($admin->id);
});

test('local_login admins_only sends reset links to admins only', function () {
    config(['queryproxy.local_login' => 'admins_only']);
    Notification::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create();

    $this->post('/forgot-password', ['email' => $member->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post('/forgot-password', ['email' => $admin->email])->assertSessionHas('status');
    Notification::assertSentTo($admin, ResetPassword::class);
});

test('an SSO user without a local password is never handed a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-x', 'password' => null]);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $token = Password::createToken($user);
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBeNull();
});

test('a user without a password cannot sign in at the password form', function () {
    $user = User::factory()->create(['auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-x', 'password' => null]);

    $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => $user->email, 'password' => 'anything'])->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

test('an SSO user is not sent to enrol in 2FA when it is required for everyone', function () {
    config(['queryproxy.require_two_factor' => 'all']);

    oidcLogin()->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();

    expect(User::where('email', 'alice@example.test')->firstOrFail()->hasTwoFactorEnabled())->toBeFalse();
});

test('a local user is still sent to enrol in 2FA when it is required for everyone', function () {
    config(['queryproxy.require_two_factor' => 'all']);
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profile'));
});

test('a linked account that signs in with its password stays under the 2FA requirement', function () {
    config(['queryproxy.require_two_factor' => 'all']);
    $user = User::factory()->create(['password' => 'secret-password', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-alice']);

    // Opened by password, not through the provider: no SSO flag in the session.
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profile'));

    // Opened through the provider: exempt.
    $this->actingAs($user)->withSession([OidcController::SESSION_FLAG => true])->get(route('dashboard'))->assertOk();
});

test('the role map ignores malformed entries and unknown teams', function () {
    config(['queryproxy.oidc.role_map' => 'a:payments:dba, bad-entry ,b:payments:boss,:payments:dba,c:ghost-team:dba, ldap:cn=x:payments:Developer']);
    [$payments] = oidcTeams();

    $map = app(OidcRoleSync::class)->roleMap();

    expect(array_map(fn ($entry) => $entry['group'].'|'.$entry['team'].'|'.$entry['role']->value, $map))
        ->toBe(['a|payments|dba', 'c|ghost-team|dba', 'ldap:cn=x|payments|developer']);

    $user = User::factory()->create();
    app(OidcRoleSync::class)->sync($user, ['a', 'c']);

    expect($user->fresh()->roleIn($payments)->value)->toBe('dba');
});

test('an empty role map changes nothing', function () {
    config(['queryproxy.oidc.role_map' => '']);
    [$payments] = oidcTeams();
    $user = User::factory()->create();
    $payments->users()->attach($user, ['role' => 'developer']);

    oidcLogin(['sub' => 'x', 'email' => $user->email]);

    expect($user->fresh()->roleIn($payments)->value)->toBe('developer');
});

test('an unverified e-mail does not provision an account and the refusal is audited', function (array $claims) {
    oidcTeams();

    oidcLogin($claims)->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcAuditActions())->toBe(['auth.sso_refused']);

    expect(AuditLog::first()->metadata)->toMatchArray(['reason' => 'provision_unverified_email', 'user' => 'alice@example.test', 'issuer' => OidcFakeProvider::ISSUER]);
})->with([
    'email_verified false' => [['email_verified' => false]],
    'email_verified missing' => [['email_verified' => null]],
    'email_verified a string other than true' => [['email_verified' => 'yes']],
]);

test('the string "true" counts as a verified e-mail for provisioning', function () {
    oidcLogin(['email_verified' => 'true'])->assertRedirect(route('dashboard'));

    expect(User::where('email', 'alice@example.test')->firstOrFail()->email_verified_at)->not->toBeNull();
});

test('a suspended local account is neither linked nor audited as linked', function () {
    $local = User::factory()->create(['email' => 'alice@example.test', 'password' => 'secret-password', 'suspended_at' => now()]);

    oidcLogin()->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => 'This account is suspended.']);

    expect(auth()->check())->toBeFalse()
        ->and($local->fresh()->auth_provider)->toBe('local')
        ->and($local->fresh()->external_id)->toBeNull()
        ->and($local->fresh()->external_issuer)->toBeNull()
        ->and(oidcAuditActions())->toBe([]);
});

test('the same subject from another issuer is another person', function () {
    $stranger = User::factory()->create([
        'email' => 'stranger@example.test', 'auth_provider' => 'oidc', 'is_admin' => true, 'password' => null,
        'external_issuer' => 'https://old-idp.test/realms/qp', 'external_id' => 'sub-alice',
    ]);

    // Alice's subject equals the stranger's, her issuer does not: she is not him.
    oidcLogin(['email' => 'alice@example.test'])->assertRedirect(route('dashboard'));

    expect(auth()->id())->not->toBe($stranger->id)
        ->and(User::where('email', 'alice@example.test')->firstOrFail()->external_issuer)->toBe(OidcFakeProvider::ISSUER)
        ->and(User::count())->toBe(2);
});

test('an account tied to another issuer is not taken over by e-mail after the issuer changed', function () {
    $user = User::factory()->create([
        'email' => 'alice@example.test', 'auth_provider' => 'oidc', 'password' => null,
        'external_issuer' => 'https://old-idp.test/realms/qp', 'external_id' => 'sub-alice',
    ]);

    oidcLogin()->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()->and($user->fresh()->external_issuer)->toBe('https://old-idp.test/realms/qp');
});

test('SSO routes count against a bucket of their own, not the one of the password form', function () {
    for ($i = 0; $i < 12; $i++) {
        $this->get(route('oidc.redirect'))->assertRedirect();
    }

    // /login allows 10 per minute per address; the SSO hits above did not use them.
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'x'])->assertSessionHasErrors('email');
});

test('an SSO sign-in records when it happened, for the fresh-confirmation checks (ADR-024)', function () {
    oidcLogin()->assertRedirect(route('dashboard'));

    expect(session(OidcController::SESSION_FLAG))->toBeTrue()
        ->and(session(OidcController::SESSION_AT_KEY))->toBeInt()
        ->and(abs(session(OidcController::SESSION_AT_KEY) - now()->getTimestamp()))->toBeLessThan(5);
});

test('reauthenticating asks the provider to authenticate the user again and returns to the token section', function () {
    oidcLogin()->assertRedirect(route('dashboard'));
    $user = auth()->user();
    session([OidcController::SESSION_AT_KEY => now()->subHour()->getTimestamp()]);

    $location = $this->get(route('oidc.reauthenticate'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];

    OidcFakeProvider::$claims = ['auth_time' => time()];

    expect($location)->toStartWith('https://idp.test/auth?')
        ->and($query['prompt'])->toBe('login')
        ->and($query['max_age'])->toBe('0')
        ->and(session('oidc.flow.reauth_user_id'))->toBe($user->id);

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(route('profile').'#api-tokens');

    expect(auth()->id())->toBe($user->id)
        ->and(now()->getTimestamp() - session(OidcController::SESSION_AT_KEY))->toBeLessThan(5);
});

test('an ordinary sign-in does not ask the provider to authenticate again', function () {
    expect(oidcStartLogin())->not->toHaveKey('prompt');
});

test('a confirmation that comes back as another account is refused and the session is left as it was', function () {
    oidcLogin()->assertRedirect(route('dashboard'));
    $alice = auth()->user();
    $oldAt = now()->subHour()->getTimestamp();
    session([OidcController::SESSION_AT_KEY => $oldAt]);

    $location = $this->get(route('oidc.reauthenticate'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];
    OidcFakeProvider::$claims = ['sub' => 'sub-bob', 'email' => 'bob@example.test', 'name' => 'Bob', 'auth_time' => time()];

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(route('profile').'#api-tokens')
        ->assertSessionHasErrors('sso');

    // Refused before anything is provisioned or linked.
    expect(auth()->id())->toBe($alice->id)
        ->and(session(OidcController::SESSION_AT_KEY))->toBe($oldAt)
        ->and(User::where('email', 'bob@example.test')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'user.sso_provisioned')->count())->toBe(1);
});

test('a signed-in user who did not start a confirmation is still turned away from the callback', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'x']))
        ->assertRedirect(route('dashboard'));

    Http::assertNothingSent();
});

test('reauthenticating needs a signed-in user', function () {
    $this->get(route('oidc.reauthenticate'))->assertRedirect(route('login'));
});

test('a confirmation the provider cannot date, or dates before it began, is refused (auth_time)', function (?int $offset) {
    oidcLogin()->assertRedirect(route('dashboard'));
    $alice = auth()->user();
    $oldAt = now()->subHour()->getTimestamp();
    session([OidcController::SESSION_AT_KEY => $oldAt]);

    $location = $this->get(route('oidc.reauthenticate'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];
    // The provider ignored prompt=login: it answers with the sign-in it already had.
    OidcFakeProvider::$claims = ['auth_time' => $offset === null ? null : time() + $offset];

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(route('profile').'#api-tokens')
        ->assertSessionHasErrors('sso');

    expect(auth()->id())->toBe($alice->id)
        ->and(session(OidcController::SESSION_AT_KEY))->toBe($oldAt);
})->with([
    'no auth_time claim' => [null],
    'signed in an hour ago' => [-3600],
    'signed in two minutes ago' => [-120],
]);

test('a confirmation that carries an auth_time from after the attempt began is accepted', function () {
    oidcLogin()->assertRedirect(route('dashboard'));
    $alice = auth()->user();
    session([OidcController::SESSION_AT_KEY => now()->subHour()->getTimestamp()]);

    $location = $this->get(route('oidc.reauthenticate'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];
    OidcFakeProvider::$claims = ['auth_time' => time() - 10];

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(route('profile').'#api-tokens')
        ->assertSessionHasNoErrors();

    expect(auth()->id())->toBe($alice->id)
        ->and(now()->getTimestamp() - session(OidcController::SESSION_AT_KEY))->toBeLessThan(5);
});

test('auth_time from the userinfo endpoint is not taken as proof of a fresh sign-in', function () {
    oidcLogin()->assertRedirect(route('dashboard'));
    $oldAt = now()->subHour()->getTimestamp();
    session([OidcController::SESSION_AT_KEY => $oldAt]);

    $location = $this->get(route('oidc.reauthenticate'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];
    // No auth_time in the ID token, and no groups, so the userinfo merge runs and offers one.
    OidcFakeProvider::$claims = ['groups' => null];
    OidcFakeProvider::$userInfo = ['groups' => ['platform-dba'], 'auth_time' => time()];

    $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(route('profile').'#api-tokens')
        ->assertSessionHasErrors('sso');

    expect(session(OidcController::SESSION_AT_KEY))->toBe($oldAt);
});

test('an Entra-like token with only xms_edov true provisions an account', function (mixed $edov) {
    oidcTeams();

    oidcLogin(['email_verified' => null, 'xms_edov' => $edov])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'alice@example.test')->firstOrFail();
    expect(auth()->id())->toBe($user->id)->and($user->email_verified_at)->not->toBeNull();
})->with([
    'boolean true' => [true],
    'string "true"' => ['true'],
]);

test('an Entra-like token with only xms_edov true links a local account', function () {
    $local = User::factory()->create(['email' => 'alice@example.test', 'password' => 'secret-password']);

    oidcLogin(['email_verified' => null, 'xms_edov' => true])->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($local->id)
        ->and($local->fresh()->auth_provider)->toBe('oidc')
        ->and(oidcAuditActions())->toContain('user.sso_linked');
});

test('without email_verified, xms_edov must be true to count', function (array $claims) {
    $local = User::factory()->create(['email' => 'alice@example.test', 'password' => 'secret-password']);

    oidcLogin($claims)->assertRedirect(route('login'))->assertSessionHasErrors('sso');
    expect(auth()->check())->toBeFalse()->and($local->fresh()->auth_provider)->toBe('local');

    $local->forceDelete();

    oidcLogin($claims)->assertRedirect(route('login'))->assertSessionHasErrors('sso');
    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0)
        ->and(oidcAuditActions())->toBe(['auth.sso_refused', 'auth.sso_refused']);
})->with([
    'xms_edov false' => [['email_verified' => null, 'xms_edov' => false]],
    'xms_edov string "false"' => [['email_verified' => null, 'xms_edov' => 'false']],
    'xms_edov missing' => [['email_verified' => null]],
    'xms_edov 1' => [['email_verified' => null, 'xms_edov' => 1]],
    'xms_edov "yes"' => [['email_verified' => null, 'xms_edov' => 'yes']],
    'xms_edov "1"' => [['email_verified' => null, 'xms_edov' => '1']],
]);

test('an explicit email_verified is never overridden by xms_edov', function (mixed $verified) {
    $local = User::factory()->create(['email' => 'alice@example.test', 'password' => 'secret-password']);

    oidcLogin(['email_verified' => $verified, 'xms_edov' => true])->assertRedirect(route('login'))->assertSessionHasErrors('sso');
    expect(auth()->check())->toBeFalse()->and($local->fresh()->auth_provider)->toBe('local');

    $local->forceDelete();

    oidcLogin(['email_verified' => $verified, 'xms_edov' => true])->assertRedirect(route('login'))->assertSessionHasErrors('sso');
    expect(auth()->check())->toBeFalse()->and(User::count())->toBe(0);
})->with([
    'false' => [false],
    'string "false"' => ['false'],
    'string "yes"' => ['yes'],
]);

/** How many times the fake provider's userinfo endpoint was asked. */
function oidcUserInfoRequests(): int
{
    return Http::recorded(fn (Request $request) => $request->url() === 'https://idp.test/userinfo')->count();
}

test('xms_edov from the userinfo endpoint counts when the ID token says nothing about verification', function () {
    OidcFakeProvider::$userInfo = ['xms_edov' => true];

    oidcLogin(['email_verified' => null])->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and(oidcUserInfoRequests())->toBe(1); // the endpoint really was asked
});

test('a userinfo xms_edov of false or garbage does not verify the address', function (mixed $edov) {
    OidcFakeProvider::$userInfo = ['xms_edov' => $edov];

    oidcLogin(['email_verified' => null])->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcUserInfoRequests())->toBe(1);
})->with([
    'false' => [false],
    'string "yes"' => ['yes'],
]);

test('an explicit email_verified false in the token ends the matter: userinfo is not even asked', function () {
    OidcFakeProvider::$userInfo = ['xms_edov' => true, 'email_verified' => true];

    oidcLogin(['email_verified' => false])->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcUserInfoRequests())->toBe(0);
});

test('userinfo, asked for the groups, cannot overwrite an email_verified false the token carries', function () {
    config(['queryproxy.oidc.role_map' => '']);
    OidcFakeProvider::$userInfo = ['groups' => ['platform-dba'], 'xms_edov' => true, 'email_verified' => true];

    // The token has no group claim, so the endpoint is asked; what it adds is only what the token lacks.
    oidcLogin(['email_verified' => false, 'groups' => null])->assertRedirect(route('login'))->assertSessionHasErrors('sso');

    expect(auth()->check())->toBeFalse()
        ->and(User::count())->toBe(0)
        ->and(oidcUserInfoRequests())->toBe(1);
});

test('the userinfo endpoint is not called when the token already speaks of verification', function () {
    OidcFakeProvider::$userInfo = ['groups' => ['platform-dba']];

    oidcLogin()->assertRedirect(route('dashboard'));

    expect(oidcUserInfoRequests())->toBe(0);
});

test('logging out of an SSO session sends the browser to the provider with the id_token_hint', function () {
    OidcFakeProvider::$endSession = 'https://idp.test/logout';

    oidcLogin()->assertRedirect(route('dashboard'));
    $idToken = session(OidcController::SESSION_ID_TOKEN_KEY);
    expect($idToken)->toBeString()->not->toBe('');

    $location = $this->post('/logout')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://idp.test/logout?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toBe([
        'id_token_hint' => $idToken,
        'client_id' => OidcFakeProvider::CLIENT_ID,
        'post_logout_redirect_uri' => route('login'),
    ])
        ->and(auth()->check())->toBeFalse()
        ->and(session(OidcController::SESSION_ID_TOKEN_KEY))->toBeNull()
        ->and(oidcAuditActions())->toContain('auth.logout');
});

test('the id_token never reaches the audit log', function () {
    OidcFakeProvider::$endSession = 'https://idp.test/logout';

    oidcLogin()->assertRedirect(route('dashboard'));
    $idToken = session(OidcController::SESSION_ID_TOKEN_KEY);
    $this->post('/logout');

    expect(json_encode(AuditLog::all()->toArray()))->not->toContain($idToken);
});

test('logging out of an SSO session without an end_session_endpoint only ends the local session', function () {
    oidcLogin()->assertRedirect(route('dashboard'));

    $this->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

test('an end_session_endpoint that is not an http(s) URL is ignored', function () {
    OidcFakeProvider::$endSession = 'javascript:alert(1)';

    oidcLogin()->assertRedirect(route('dashboard'));

    $this->post('/logout')->assertRedirect(route('login'));
});

test('a password session of an SSO-linked user does not end the provider session', function () {
    OidcFakeProvider::$endSession = 'https://idp.test/logout';
    $user = User::factory()->create(['password' => 'secret-password', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-x']);

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
});

test('a local user logs out locally even when the provider publishes an end_session_endpoint', function () {
    OidcFakeProvider::$endSession = 'https://idp.test/logout';
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
});

test('an SSO-linked user is never handed a reset link, even with a local password', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => 'secret-password', 'auth_provider' => 'oidc', 'external_issuer' => OidcFakeProvider::ISSUER, 'external_id' => 'sub-x']);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $token = Password::createToken($user);
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
        ->assertSessionHasErrors('email');

    expect(Hash::check('secret-password', $user->fresh()->password))->toBeTrue();
});

/** Starts a confirmation as the signed-in user with the given query and returns the callback response. */
function oidcReauthenticate(array $reauthQuery, array $claims): TestResponse
{
    $location = test()->get(route('oidc.reauthenticate', $reauthQuery))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    OidcFakeProvider::$nonce = $query['nonce'];
    OidcFakeProvider::$claims = $claims;

    return test()->get(route('oidc.callback', ['code' => 'auth-code', 'state' => $query['state']]));
}

test('an admin who confirms from the SCIM screen returns to it, on success and on failure', function () {
    oidcLogin()->assertRedirect(route('dashboard'));
    auth()->user()->forceFill(['is_admin' => true])->save();
    session([OidcController::SESSION_AT_KEY => now()->subHour()->getTimestamp()]);

    oidcReauthenticate(['return' => 'scim'], ['auth_time' => time()])
        ->assertRedirect(route('settings.scim'))
        ->assertSessionHasNoErrors();

    expect(now()->getTimestamp() - session(OidcController::SESSION_AT_KEY))->toBeLessThan(5);

    // A confirmation the provider did not really renew is refused, and the error shows where the user came from.
    $oldAt = now()->subHour()->getTimestamp();
    session([OidcController::SESSION_AT_KEY => $oldAt]);

    oidcReauthenticate(['return' => 'scim'], ['auth_time' => time() - 3600])
        ->assertRedirect(route('settings.scim'))
        ->assertSessionHasErrors('sso');

    expect(session(OidcController::SESSION_AT_KEY))->toBe($oldAt);
});

test('the return target is a fixed list: unknown keys, URLs and non-admins go back to the profile', function () {
    oidcLogin()->assertRedirect(route('dashboard'));

    // Not an admin: the SCIM screen is not theirs.
    oidcReauthenticate(['return' => 'scim'], ['auth_time' => time()])->assertRedirect(route('profile').'#api-tokens');

    auth()->user()->forceFill(['is_admin' => true])->save();

    foreach (['https://evil.test/', '//evil.test', 'settings.scim', '/settings/scim', ''] as $return) {
        oidcReauthenticate(['return' => $return], ['auth_time' => time()])->assertRedirect(route('profile').'#api-tokens');
    }

    oidcReauthenticate(['return' => ['scim']], ['auth_time' => time()])->assertRedirect(route('profile').'#api-tokens');
});
