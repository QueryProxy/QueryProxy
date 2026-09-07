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
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // The response is identical whether or not the account exists, so the
        // form cannot be used to enumerate users.
        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If that address belongs to an account, a reset link is on its way.'));
    }

    public function reset(string $token): View
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

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
}
