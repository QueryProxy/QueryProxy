<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

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
