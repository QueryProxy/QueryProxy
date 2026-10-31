<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit for API calls that did not authenticate (ADR-024). The `api`
 * limiter is per token, and Laravel runs authentication before throttling, so a
 * request with a wrong or missing token never reaches it. This counts the 401
 * answers per address instead. Once an address has used up the same per-minute
 * budget a token has, a call that fails to authenticate is answered with 429
 * and Retry-After instead of 401 (and still counts, so the window keeps moving).
 *
 * A call that authenticates is never affected: it passes, is not counted, and a
 * valid client behind a shared address keeps working while someone else's wrong
 * tokens are being throttled. The cost is that authentication is still
 * attempted for a limited address, so a correct guess would succeed; a token
 * carries 40 random characters, which makes guessing one infeasible regardless.
 *
 * It is not a priority middleware by default; bootstrap/app.php puts it ahead
 * of `auth` so it can see the 401.
 */
class ThrottleFailedApiAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-auth-failures:'.$request->ip();
        $limited = RateLimiter::tooManyAttempts($key, max(1, (int) config('queryproxy.api.rate_limit_per_minute', 60)));

        $response = $next($request);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        RateLimiter::hit($key, 60);

        if ($limited) {
            return response()->json(['message' => 'Too many failed attempts.'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }

        return $response;
    }
}
