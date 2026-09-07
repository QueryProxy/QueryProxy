<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the instance-wide 2FA policy (QUERYPROXY_REQUIRE_2FA): users the
 * policy covers are funneled to their profile until they enroll. Profile and
 * logout stay reachable so enrollment is actually possible.
 */
class RequireTwoFactor
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && ! $user->hasTwoFactorEnabled()
            && $this->twoFactor->isRequiredFor($user)
            && ! $request->routeIs('profile', 'logout')) {
            return redirect()->route('profile');
        }

        return $next($request);
    }
}
