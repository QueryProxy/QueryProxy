<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\OidcController;
use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the instance-wide 2FA policy (QUERYPROXY_REQUIRE_2FA): users the
 * policy covers are funneled to their profile until they enroll. Profile and
 * logout stay reachable so enrollment is actually possible.
 *
 * Users who sign in through OIDC are outside the policy (ADR-016 amendment,
 * ADR-023): their second factor is the identity provider's business. A local
 * account that was linked to the provider and still has a password stays
 * covered when it signs in with that password.
 */
class RequireTwoFactor
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && $this->twoFactor->mustEnrol($user, $request->session()->get(OidcController::SESSION_FLAG) === true)
            && ! $request->routeIs('profile', 'logout')) {
            return redirect()->route('profile');
        }

        return $next($request);
    }
}
