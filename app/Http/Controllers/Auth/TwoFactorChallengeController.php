<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second step of the login flow for accounts with TOTP enabled. The session
 * only carries the parked user id — nothing is authenticated until a valid
 * code (or single-use recovery code) is presented.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('two_factor.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $userId = $request->session()->get('two_factor.id');

        if (! $userId || ! ($user = User::find($userId))) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $throttleKey = 'two-factor|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                    'minutes' => ceil(RateLimiter::availableIn($throttleKey) / 60),
                ]),
            ]);
        }

        $code = (string) ($validated['code'] ?? '');
        $recoveryCode = (string) ($validated['recovery_code'] ?? '');

        $valid = ($code !== '' && $twoFactor->verify((string) $user->two_factor_secret, $code))
            || ($recoveryCode !== '' && $twoFactor->consumeRecoveryCode($user, $recoveryCode));

        if (! $valid) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'code' => 'The provided two-factor code is invalid.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        audit()->record('auth.login', metadata: ['two_factor' => true]);

        return redirect()->intended(route('dashboard'));
    }
}
