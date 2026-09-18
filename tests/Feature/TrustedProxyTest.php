<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

test('no proxy is trusted and the header mask drops the forwarded host', function () {
    $this->get('/login')->assertOk();

    $headers = Request::getTrustedHeaderSet();

    // HEADER_X_FORWARDED_AWS_ELB is an alias for FOR|PROTO|PORT rather than a bit
    // of its own, so asserting the exact mask is what proves it is gone too.
    expect($headers)->toBe(
        Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
    )
        ->and($headers & Request::HEADER_X_FORWARDED_HOST)->toBe(0)
        ->and($headers & Request::HEADER_FORWARDED)->toBe(0)
        ->and(Request::getTrustedProxies())->toBe([]);
});

test('a forged x-forwarded-for cannot buy extra login attempts', function () {
    $user = User::factory()->create();

    /** @var array<int, string> $spoofedAddresses */
    $spoofedAddresses = ['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6'];

    foreach ($spoofedAddresses as $index => $address) {
        $response = $this->withHeader('X-Forwarded-For', $address)
            ->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $response->assertRedirect('/login')->assertSessionHasErrors('email');

        // The first five are rejected as bad credentials; the sixth request comes
        // from a brand new "address" and must still be refused as throttled.
        $message = session('errors')->first('email');

        if ($index < 5) {
            expect($message)->not->toContain('Too many');
        } else {
            expect($message)->toContain('Too many');
        }
    }

    // The throttle counted every attempt against the real peer address, not the
    // one the client claimed, so the per-IP key was never rotated away.
    expect(RateLimiter::tooManyAttempts(strtolower($user->email).'|127.0.0.1', 5))->toBeTrue();

    foreach ($spoofedAddresses as $address) {
        expect(RateLimiter::attempts(strtolower($user->email).'|'.$address))->toBe(0);
    }

    $this->assertGuest();
});

test('a forged x-forwarded-host cannot poison the password reset link', function () {
    Notification::fake();

    // Pin APP_URL to the host the test client actually requests, so the assertion
    // below says "the link points at this installation", not "at whatever host the
    // developer's .env happens to name".
    config(['app.url' => 'http://localhost']);

    $user = User::factory()->create();

    $this->withHeader('X-Forwarded-Host', 'evil.tld')
        ->from('/forgot-password')
        ->post('/forgot-password', ['email' => $user->email])
        ->assertRedirect('/forgot-password');

    $expectedHost = parse_url(config('app.url'), PHP_URL_HOST);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $expectedHost): bool {
        $host = parse_url($notification->toMail($user)->actionUrl, PHP_URL_HOST);

        expect($host)->toBe($expectedHost)->not->toBe('evil.tld');

        return true;
    });
});

/**
 * Run the trustHosts closure registered in bootstrap/app.php and return the
 * patterns it produces for the given trusted-host list.
 *
 * Laravel skips the TrustHosts middleware outright in the local environment and
 * under tests (TrustHosts::shouldSpecifyTrustedHosts()), so an ordinary HTTP
 * test can never observe it. Resolving the middleware and calling hosts()
 * invokes the real closure — the one bootstrap/app.php registered — which is
 * also proof that it reads configuration at request time, unlike the body of
 * the withMiddleware callback that runs before the configuration is loaded.
 *
 * @param  list<string>|null  $trustedHosts
 * @return array<int, string>
 */
function trustedHostPatterns(?array $trustedHosts): array
{
    $previous = config('queryproxy.trusted_hosts');

    try {
        config(['queryproxy.trusted_hosts' => $trustedHosts ?? []]);

        return app(TrustHosts::class)->hosts();
    } finally {
        config(['queryproxy.trusted_hosts' => $previous]);
    }
}

/**
 * Feed the patterns to Symfony exactly as TrustHosts::handle() would, then ask a
 * request carrying $host for its host. Symfony throws on an untrusted one, so
 * this asserts the patterns' real effect rather than their spelling.
 *
 * @param  array<int, string>  $patterns
 */
function hostIsTrusted(array $patterns, string $host): bool
{
    try {
        Request::setTrustedHosts($patterns);

        $request = Request::create('http://placeholder.invalid/');
        $request->headers->set('HOST', $host);

        return $request->getHost() === strtolower($host);
    } catch (SuspiciousOperationException) {
        return false;
    } finally {
        Request::setTrustedHosts([]);
    }
}

test('with TRUSTED_HOSTS empty only the APP_URL host is trusted', function () {
    config(['app.url' => 'https://queryproxy.example.com']);

    $patterns = trustedHostPatterns([]);

    expect($patterns)->toBe([
        '^queryproxy\.example\.com$',
        '^www\.queryproxy\.example\.com$',
    ])
        ->and(hostIsTrusted($patterns, 'queryproxy.example.com'))->toBeTrue()
        ->and(hostIsTrusted($patterns, 'www.queryproxy.example.com'))->toBeTrue()
        ->and(hostIsTrusted($patterns, 'evil.tld'))->toBeFalse()
        ->and(hostIsTrusted($patterns, 'queryproxy.example.com.evil.tld'))->toBeFalse();

    // An unset variable must behave the same as an empty one.
    expect(trustedHostPatterns(null))->toBe($patterns);
});

test('hostnames listed in TRUSTED_HOSTS are trusted alongside the APP_URL host', function () {
    config(['app.url' => 'https://queryproxy.example.com']);

    $patterns = trustedHostPatterns(['queryproxy.internal', 'queryproxy.example.net']);

    expect($patterns)->toBe([
        '^queryproxy\.example\.com$',
        '^www\.queryproxy\.example\.com$',
        '^queryproxy\.internal$',
        '^queryproxy\.example\.net$',
    ])
        ->and(hostIsTrusted($patterns, 'queryproxy.internal'))->toBeTrue()
        ->and(hostIsTrusted($patterns, 'queryproxy.example.net'))->toBeTrue()
        // Adding a host must not widen the ones already trusted, nor trust
        // anything that merely contains a listed name.
        ->and(hostIsTrusted($patterns, 'queryproxy.example.com'))->toBeTrue()
        ->and(hostIsTrusted($patterns, 'evil.tld'))->toBeFalse()
        ->and(hostIsTrusted($patterns, 'notqueryproxy.internal'))->toBeFalse()
        ->and(hostIsTrusted($patterns, 'queryproxy.internal.evil.tld'))->toBeFalse();
});

test('blank and malformed TRUSTED_HOSTS entries are dropped without breaking the install', function () {
    config(['app.url' => 'https://queryproxy.example.com']);

    // Stray commas, whitespace, a pasted URL and a regex metacharacter: each is
    // skipped rather than becoming a pattern that matches more than its author meant.
    $patterns = trustedHostPatterns([' ', 'queryproxy.internal', '', 'https://oops.example.com', '.*']);

    expect($patterns)->toBe([
        '^queryproxy\.example\.com$',
        '^www\.queryproxy\.example\.com$',
        '^queryproxy\.internal$',
    ])
        ->and(hostIsTrusted($patterns, 'queryproxy.internal'))->toBeTrue()
        ->and(hostIsTrusted($patterns, 'oops.example.com'))->toBeFalse()
        ->and(hostIsTrusted($patterns, 'anything.tld'))->toBeFalse();
});

test('a half-configured install with no APP_URL host and no TRUSTED_HOSTS stays bootable', function () {
    config(['app.url' => '']);

    expect(trustedHostPatterns([]))->toBe([]);
});
