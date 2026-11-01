<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View|RedirectResponse
    {
        if ($this->resetDisabled()) {
            return redirect()->route('login')->withErrors(['email' => __('Password reset is disabled. Use single sign-on.')]);
        }

        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // The response is identical whether or not the account exists (or may
        // reset a password at all), so the form cannot be used to enumerate users.
        if (! $this->resetDisabled() && $this->mayReset((string) $request->input('email'))) {
            Password::sendResetLink($request->only('email'));
        }

        return back()->with('status', __('If that address belongs to an account, a reset link is on its way.'));
    }

    public function reset(string $token): View|RedirectResponse
    {
        if ($this->resetDisabled()) {
            return redirect()->route('login')->withErrors(['email' => __('Password reset is disabled. Use single sign-on.')]);
        }

        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($this->resetDisabled()) {
            return redirect()->route('login')->withErrors(['email' => __('Password reset is disabled. Use single sign-on.')]);
        }

        // Same answer as an invalid token for an account that may not reset.
        if (! $this->mayReset((string) $request->input('email'))) {
            return back()->withErrors(['email' => __(Password::INVALID_TOKEN)]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update(['password' => $password]);

                audit()->record('auth.password_reset', actor: $user);
            },
        );

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __('Your password has been reset. You can log in now.'))
            : back()->withErrors(['email' => __($status)]);
    }

    /** QUERYPROXY_LOCAL_LOGIN=none switches password reset off together with password login. */
    private function resetDisabled(): bool
    {
        return config('queryproxy.local_login') === 'none';
    }

    /**
     * Whether a reset may be offered to this address. Under `admins_only` only
     * system admins have a use for a password. An account tied to the
     * identity provider must never be handed one (ADR-016 Amendment
     * 2026-10-26, ADR-023): a local password would outlive its
     * deprovisioning there. That holds under `all` too.
     */
    private function mayReset(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            // Unknown address: let the broker answer as it always did.
            return true;
        }

        if ($user->isSsoUser()) {
            return false;
        }

        return config('queryproxy.local_login') !== 'admins_only' || $user->isAdmin();
    }
}
