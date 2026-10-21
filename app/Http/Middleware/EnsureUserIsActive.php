<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was suspended while signed in. Soft-deleted
 * users never get this far: the auth provider no longer resolves them. Session
 * rows are also dropped when the admin suspends, so this is the backstop for
 * non-database session drivers.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'This account is suspended.');
            }

            return redirect()->route('login')->withErrors(['email' => __('This account is suspended.')]);
        }

        return $next($request);
    }
}
