<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(OidcClient $oidc): View
    {
        return view('auth.login', [
            'ssoEnabled' => $oidc->isConfigured(),
            'localLogin' => config('queryproxy.local_login'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // QUERYPROXY_LOCAL_LOGIN (ADR-016 amendment): with `none` nobody signs in
        // with a password, whoever they are and whatever they typed.
        if (config('queryproxy.local_login') === 'none') {
            throw ValidationException::withMessages([
                'email' => __('Password login is disabled. Use single sign-on.'),
            ]);
        }

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                    'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60),
                ]),
            ]);
        }

        // `admins_only`: a non-admin is refused like a wrong password, before the
        // password is looked at, so the answer says nothing about it.
        if (config('queryproxy.local_login') === 'admins_only'
            && ! User::where('email', $credentials['email'])->where('is_admin', true)->exists()) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $user = User::where('email', $credentials['email'])->firstOrFail();

        // Right credentials, but the account is suspended: refuse before a
        // session (or the 2FA step) exists.
        if ($user->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => __('This account is suspended.'),
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            // Credentials are correct but the session is not authenticated yet:
            // park the login and demand the TOTP code first.
            $request->session()->put([
                'two_factor.id' => $user->id,
                'two_factor.remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        audit()->record('auth.login');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, OidcClient $oidc): RedirectResponse
    {
        audit()->record('auth.logout');

        // Read before the session is wiped. Only a session opened through SSO
        // also ends the provider's session (RP-initiated logout); a password
        // login of the same person does not touch it.
        $viaSso = $request->session()->get(OidcController::SESSION_FLAG) === true;
        $idToken = $request->session()->get(OidcController::SESSION_ID_TOKEN_KEY);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($viaSso && $oidc->isConfigured()) {
            // The return address is fixed, never taken from the request.
            $url = $oidc->logoutUrl(is_string($idToken) ? $idToken : null, route('login'));

            if ($url !== null) {
                return redirect()->away($url);
            }
        }

        return redirect()->route('login');
    }
}
