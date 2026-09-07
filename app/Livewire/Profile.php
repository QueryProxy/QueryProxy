<?php

namespace App\Livewire;

use App\Services\Auth\TwoFactorService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile')]
class Profile extends Component
{
    public string $name = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $twoFactorCode = '';

    public string $disablePassword = '';

    public function mount(): void
    {
        $this->name = auth()->user()->name;
    }

    public function updateName(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        auth()->user()->update(['name' => $this->name]);

        audit()->record('user.name_changed');

        session()->flash('status', 'Name updated.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], attributes: [
            'currentPassword' => 'current password',
        ]);

        auth()->user()->update(['password' => $this->password]);

        // logoutOtherDevices + AuthenticateSession middleware end every other
        // live session for this account, so a stolen session dies here too.
        // (Called after the update: it re-authenticates with the current hash.)
        auth()->logoutOtherDevices($this->password);

        audit()->record('user.password_changed');

        $this->reset('currentPassword', 'password', 'password_confirmation');

        session()->flash('status', 'Password changed.');
    }

    /**
     * Step 1 of enrollment: mint a secret. It lives in the session (not in a
     * public Livewire property) until the user confirms with a valid code.
     */
    public function startTwoFactorEnrollment(TwoFactorService $twoFactor): void
    {
        if (auth()->user()->hasTwoFactorEnabled()) {
            return;
        }

        session()->put('two_factor_setup_secret', $twoFactor->generateSecret());
    }

    public function cancelTwoFactorEnrollment(): void
    {
        session()->forget('two_factor_setup_secret');
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->resetErrorBag('twoFactorCode');

        $user = auth()->user();
        $secret = (string) session('two_factor_setup_secret', '');

        if ($secret === '' || $user->hasTwoFactorEnabled()) {
            return;
        }

        if (! $twoFactor->verify($secret, $this->twoFactorCode)) {
            $this->addError('twoFactorCode', 'That code does not match — check your authenticator app and try again.');

            return;
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $codes = $twoFactor->issueRecoveryCodes($user);

        session()->forget('two_factor_setup_secret');
        $this->reset('twoFactorCode');

        audit()->record('user.two_factor_enabled');

        session()->flash('recovery_codes', $codes);
        session()->flash('status', 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(TwoFactorService $twoFactor): void
    {
        $this->validate([
            'disablePassword' => ['required', 'current_password'],
        ], attributes: ['disablePassword' => 'password']);

        $twoFactor->disable(auth()->user());
        $this->reset('disablePassword');

        audit()->record('user.two_factor_disabled');

        session()->flash('status', 'Two-factor authentication disabled.');
    }

    public function render(TwoFactorService $twoFactor)
    {
        $user = auth()->user();
        $setupSecret = session('two_factor_setup_secret');

        return view('livewire.profile', [
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'twoFactorRequired' => $twoFactor->isRequiredFor($user),
            'setupSecret' => $setupSecret,
            'setupQrSvg' => $setupSecret ? $twoFactor->qrCodeSvg($user, $setupSecret) : null,
        ]);
    }
}
