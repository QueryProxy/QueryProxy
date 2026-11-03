<?php

namespace App\Http\Middleware;

use App\Models\ScimToken;
use App\Services\Scim\ScimAudit;
use App\Services\Scim\ScimException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /scim/v2 (ADR-026). The route group is outside the web group: a SCIM
 * client has no session or CSRF token, and it authenticates with a SCIM token,
 * never a personal access token or a login.
 *
 *  - SCIM off (the default): every path is 404, as if it did not exist.
 *  - The bearer value is hashed and looked up; a missing, wrong or revoked one
 *    gets 401 with a SCIM Error body.
 *  - Failed attempts are counted per address. Once an address has used up the
 *    per-minute budget, a call that fails to authenticate is answered with 429
 *    and Retry-After instead of 401 (the same rule as ThrottleFailedApiAuthentication);
 *    a call that authenticates is never affected.
 *  - An authenticated token has a per-minute budget of its own.
 */
class AuthenticateScimToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Never carry a token over from an earlier call in the same process.
        app()->forgetInstance(ScimAudit::TOKEN_BINDING);

        if (! config('queryproxy.scim.enabled')) {
            return ScimException::response(404, 'Not found.');
        }

        $limit = max(1, (int) config('queryproxy.scim.rate_limit_per_minute', 300));

        $failureKey = 'scim-auth-failures:'.$request->ip();
        $addressLimited = RateLimiter::tooManyAttempts($failureKey, $limit);

        $token = ScimToken::findActive((string) $request->bearerToken());

        if ($token === null) {
            RateLimiter::hit($failureKey, 60);

            if ($addressLimited) {
                return ScimException::response(429, 'Too many failed attempts.', null, [
                    'Retry-After' => (string) RateLimiter::availableIn($failureKey),
                ]);
            }

            return ScimException::response(401, 'A valid SCIM bearer token is required.', null, [
                'WWW-Authenticate' => 'Bearer realm="scim"',
            ]);
        }

        $tokenKey = 'scim-token:'.$token->id;

        if (RateLimiter::tooManyAttempts($tokenKey, $limit)) {
            return ScimException::response(429, 'Too many requests.', null, [
                'Retry-After' => (string) RateLimiter::availableIn($tokenKey),
            ]);
        }

        RateLimiter::hit($tokenKey, 60);

        // At most one write a minute, not one per call.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        app()->instance(ScimAudit::TOKEN_BINDING, $token);

        return $next($request);
    }
}
