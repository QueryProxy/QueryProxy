<?php

/*
 * OIDC sign-in and group-to-role sync against a real Keycloak (ADR-023). The
 * test plays the browser: it follows the authorization URL to Keycloak, posts
 * the login form, and hands the redirect back to the application, which then
 * talks to Keycloak itself (discovery, JWKS, token endpoint).
 *
 * Group "integration"; skipped without QUERYPROXY_IT_OIDC_ISSUER. To run it
 * by hand (the realm file is the same one CI imports):
 *
 *   docker run --rm -p 8080:8080 -e KC_BOOTSTRAP_ADMIN_USERNAME=admin \
 *     -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin \
 *     -v "$PWD/tests/Integration/keycloak:/opt/keycloak/data/import:ro" \
 *     quay.io/keycloak/keycloak:26.8.1 start-dev --import-realm
 *   QUERYPROXY_IT_OIDC_ISSUER=http://localhost:8080/realms/queryproxy \
 *     ./vendor/bin/pest tests/Integration/Oidc/KeycloakLoginTest.php
 *
 *   QUERYPROXY_IT_OIDC_ISSUER         issuer URL of the realm (required to run)
 *   QUERYPROXY_IT_OIDC_CLIENT_SECRET  secret of the "queryproxy" client (default: the one in the realm file)
 *   QUERYPROXY_IT_OIDC_ADMIN_USER     Keycloak admin, used to change group membership (default admin)
 *   QUERYPROXY_IT_OIDC_ADMIN_PASSWORD (default admin)
 *   QUERYPROXY_IT_OIDC_REQUIRED       1 = fail instead of skip when Keycloak is not there
 */

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function keycloakIssuer(): string
{
    return rtrim((string) getenv('QUERYPROXY_IT_OIDC_ISSUER'), '/');
}

/** Base URL of the Keycloak server, from the issuer of the realm. */
function keycloakBase(): string
{
    return preg_replace('#/realms/[^/]+$#', '', keycloakIssuer());
}

function keycloakAdminToken(): string
{
    $response = Http::asForm()->post(keycloakBase().'/realms/master/protocol/openid-connect/token', [
        'grant_type' => 'password',
        'client_id' => 'admin-cli',
        'username' => getenv('QUERYPROXY_IT_OIDC_ADMIN_USER') ?: 'admin',
        'password' => getenv('QUERYPROXY_IT_OIDC_ADMIN_PASSWORD') ?: 'admin',
    ]);

    test()->assertTrue($response->successful(), 'Keycloak admin login failed (HTTP '.$response->status().').');

    return $response->json('access_token');
}

/** Puts alice into the group, or takes her out of it. */
function keycloakSetMembership(string $group, bool $member): void
{
    $admin = Http::withToken(keycloakAdminToken())->acceptJson();
    $realm = keycloakBase().'/admin/realms/queryproxy';

    $userId = $admin->get($realm.'/users', ['username' => 'alice', 'exact' => 'true'])->json('0.id');
    $groupId = $admin->get($realm.'/groups', ['search' => $group, 'exact' => 'true'])->json('0.id');

    expect($userId)->not->toBeNull()->and($groupId)->not->toBeNull();

    $response = $member
        ? $admin->put($realm."/users/{$userId}/groups/{$groupId}")
        : $admin->delete($realm."/users/{$userId}/groups/{$groupId}");

    test()->assertTrue($response->successful(), 'Changing group membership failed (HTTP '.$response->status().').');
}

/**
 * The state of the shared Keycloak is part of the test: alice starts outside
 * both mapped groups but stays in "unrelated". Keycloak leaves the groups claim
 * out of the token for a user who is in no group at all, and QueryProxy refuses
 * a sign-in without that claim while a role map is set, so a user "removed from
 * the group" must still be in some group for the sign-in to succeed.
 */
function keycloakResetMembership(): void
{
    keycloakSetMembership('platform-dba', false);
    keycloakSetMembership('platform-dev', false);
    keycloakSetMembership('unrelated', true);
}

/**
 * A browser, as far as Keycloak's login pages are concerned: it keeps the
 * cookies a response sets and sends them back, and it never follows redirects.
 *
 * Guzzle's own CookieJar is not used on purpose. Keycloak 26 treats
 * http://localhost as a secure context (as browsers do) and marks its cookies
 * `Secure`; Guzzle holds a Secure cookie back on any http:// URL, so the login
 * form was posted without AUTH_SESSION_ID and Keycloak answered 400 "Cookie not
 * found". Here every cookie goes back to the host it came from, whatever its
 * flags, which is what a browser does for localhost.
 */
final class KeycloakBrowser
{
    /** @var array<string, string> cookie name => value */
    private array $cookies = [];

    public function get(string $url): Response
    {
        return $this->send(fn ($request) => $request->get($url));
    }

    /** @param array<string, string> $form */
    public function submit(string $url, array $form): Response
    {
        return $this->send(fn ($request) => $request->asForm()->post($url, $form));
    }

    /** What went wrong in a page that was not what we expected: no cookie values, no secrets. */
    public function describe(Response $response): string
    {
        $text = (string) preg_replace('#<(script|style|head)\b.*?</\1>#si', ' ', $response->body());
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text))));

        $setCookies = array_map(function (string $header) {
            $parts = array_map('trim', explode(';', $header));

            return explode('=', $parts[0], 2)[0].' ['.implode('; ', array_slice($parts, 1)).']';
        }, $response->toPsrResponse()->getHeader('Set-Cookie'));

        return sprintf(
            "HTTP %d. Page text: %s\nCookies held (names only): %s\nSet-Cookie of this response: %s",
            $response->status(),
            substr($text, 0, 500),
            implode(', ', array_keys($this->cookies)) ?: '(none)',
            implode(' | ', $setCookies) ?: '(none)',
        );
    }

    private function send(callable $call): Response
    {
        $request = Http::withOptions(['allow_redirects' => false]);

        if ($this->cookies !== []) {
            $request = $request->withHeaders(['Cookie' => implode('; ', array_map(
                fn (string $name, string $value) => $name.'='.$value,
                array_keys($this->cookies),
                $this->cookies,
            ))]);
        }

        $response = $call($request);

        foreach ($response->toPsrResponse()->getHeader('Set-Cookie') as $header) {
            $this->remember($header);
        }

        return $response;
    }

    private function remember(string $header): void
    {
        $parts = array_map('trim', explode(';', $header));
        [$name, $value] = array_pad(explode('=', array_shift($parts), 2), 2, '');

        $expired = $value === '' || array_filter($parts, fn (string $attribute) => preg_match('/^max-age=0*$/i', $attribute)) !== [];

        if ($expired) {
            unset($this->cookies[$name]);

            return;
        }

        $this->cookies[$name] = $value;
    }
}

/**
 * One complete browser sign-in as alice: the redirect the application gives,
 * Keycloak's login form, the credentials, and the callback back to the
 * application. A fresh browser every time, so Keycloak always shows its form.
 */
function keycloakSignIn(): TestResponse
{
    $redirect = test()->get(route('oidc.redirect'))->assertRedirect();
    $authorizationUrl = $redirect->headers->get('Location');

    expect($authorizationUrl)->toStartWith(keycloakIssuer().'/protocol/openid-connect/auth');

    $browser = new KeycloakBrowser;

    $page = $browser->get($authorizationUrl);
    test()->assertSame(200, $page->status(), 'Keycloak did not show its login page. '.$browser->describe($page));

    test()->assertSame(1, preg_match('/<form\b[^>]*\bid="kc-form-login"[^>]*>/s', $page->body(), $form), 'No login form on the Keycloak page. '.$browser->describe($page));
    test()->assertSame(1, preg_match('/\baction="([^"]+)"/', $form[0], $action));

    $submitted = $browser->submit(html_entity_decode($action[1]), [
        'username' => 'alice',
        'password' => 'alice-password',
        'credentialId' => '',
    ]);

    test()->assertSame(302, $submitted->status(), 'Keycloak did not accept the login. '.$browser->describe($submitted));

    $callback = parse_url($submitted->header('Location'));
    test()->assertSame('/auth/oidc/callback', $callback['path'] ?? null, 'Keycloak redirected somewhere unexpected: '.$submitted->header('Location'));

    return test()->get($callback['path'].'?'.$callback['query']);
}

beforeEach(function () {
    if (keycloakIssuer() === '') {
        if (getenv('QUERYPROXY_IT_OIDC_REQUIRED') === '1') {
            test()->fail('QUERYPROXY_IT_OIDC_REQUIRED is set but QUERYPROXY_IT_OIDC_ISSUER is not.');
        }

        test()->markTestSkipped('Set QUERYPROXY_IT_OIDC_ISSUER (see the header of this file) to run the Keycloak test.');
    }

    $discovery = rescue(fn () => Http::timeout(5)->get(keycloakIssuer().'/.well-known/openid-configuration'), null, false);

    if (! $discovery || ! $discovery->successful()) {
        if (getenv('QUERYPROXY_IT_OIDC_REQUIRED') === '1') {
            test()->fail('Keycloak does not answer at '.keycloakIssuer().'.');
        }

        test()->markTestSkipped('Keycloak does not answer at '.keycloakIssuer().'.');
    }

    config([
        'queryproxy.oidc' => [
            'issuer' => keycloakIssuer(),
            'client_id' => 'queryproxy',
            'client_secret' => getenv('QUERYPROXY_IT_OIDC_CLIENT_SECRET') ?: 'queryproxy-ci-secret',
            'group_claim' => 'groups',
            'role_map' => 'platform-dba:payments:dba,platform-dev:payments:developer',
            'scopes' => 'openid email profile',
        ],
        'queryproxy.local_login' => 'all',
    ]);

    keycloakResetMembership();
});

afterEach(function () {
    if (keycloakIssuer() !== '') {
        keycloakResetMembership();
    }
});

test('a user in the platform-dba group becomes DBA of payments, and loses the role when removed from the group', function () {
    $payments = Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']);

    keycloakSetMembership('platform-dba', true);

    keycloakSignIn()->assertRedirect(route('dashboard'));

    $alice = User::where('email', 'alice@example.test')->firstOrFail();

    expect(Auth::id())->toBe($alice->id)
        ->and($alice->auth_provider)->toBe('oidc')
        ->and($alice->password)->toBeNull()
        ->and($alice->external_issuer)->toBe(keycloakIssuer())
        ->and($alice->external_id)->not->toBeEmpty()
        ->and($alice->roleIn($payments)?->value)->toBe('dba');

    // Out of the group at the provider; the role drops at the next sign-in.
    Auth::logout();
    keycloakSetMembership('platform-dba', false);

    keycloakSignIn()->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($alice->fresh()->roleIn($payments))->toBeNull();

    $synced = AuditLog::where('action', 'user.role_synced')->orderBy('id')->get();

    expect($synced->map(fn ($log) => $log->metadata['change'].':'.$log->metadata['to'])->all())->toBe(['added:dba', 'removed:']);
    expect(AuditLog::where('action', 'auth.sso_login')->count())->toBe(2)
        ->and(AuditLog::where('action', 'user.sso_provisioned')->count())->toBe(1);
});

test('a move between groups changes the role instead of adding a second one', function () {
    $payments = Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']);

    keycloakSetMembership('platform-dev', true);
    keycloakSignIn()->assertRedirect(route('dashboard'));

    $alice = User::where('email', 'alice@example.test')->firstOrFail();
    expect($alice->roleIn($payments)?->value)->toBe('developer');

    Auth::logout();
    keycloakSetMembership('platform-dev', false);
    keycloakSetMembership('platform-dba', true);
    keycloakSignIn()->assertRedirect(route('dashboard'));

    expect($alice->fresh()->roleIn($payments)?->value)->toBe('dba')
        ->and($payments->users()->count())->toBe(1);
});
