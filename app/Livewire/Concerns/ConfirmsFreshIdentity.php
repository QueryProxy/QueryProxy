<?php

namespace App\Livewire\Concerns;

use App\Http\Controllers\Auth\OidcController;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The fresh confirmation a screen asks for before it mints a long-lived
 * credential (ADR-024): the local password, a code from the authenticator app,
 * or, for a user without a local password, an SSO sign-in within the last
 * SSO_FRESH_MINUTES. Shared by the API tokens and the SCIM tokens screens.
 *
 * Failures are reported on the "confirmation" error key. Wrong tries are
 * counted per user, not per screen, under one shared key: every screen that
 * uses this trait checks the same password, so separate counters would hand a
 * stolen session one budget of guesses per screen.
 */
trait ConfirmsFreshIdentity
{
    /** How recent an SSO sign-in must be to create a token without a local password (ADR-024). */
    public const SSO_FRESH_MINUTES = 15;

    /** Wrong confirmations allowed per minute before the user must wait. */
    private const CONFIRM_ATTEMPTS = 5;

    /** Fresh confirmation (ADR-024): the local password, or a code from the authenticator app. */
    public string $confirmPassword = '';

    public string $confirmCode = '';

    /**
     * The rate limiter key for wrong confirmations of this user, the same on
     * every screen. It keeps the name the API tokens screen has always used.
     */
    private function confirmationRateKey(User $user): string
    {
        return 'api-token-confirm:'.$user->id;
    }

    /** A user without a local password confirms through SSO instead of typing one. */
    private function confirmsWithSso(User $user): bool
    {
        return $user->getAuthPassword() === null;
    }

    /** Was this session opened through SSO within the last SSO_FRESH_MINUTES? */
    private function ssoIsFresh(): bool
    {
        $at = session(OidcController::SESSION_AT_KEY);

        return session(OidcController::SESSION_FLAG) === true
            && is_int($at)
            && $at <= now()->getTimestamp()
            && now()->getTimestamp() - $at <= self::SSO_FRESH_MINUTES * 60;
    }

    /** Is the confirmation good? Adds a "confirmation" error and returns false when it is not. */
    private function confirmed(User $user, TwoFactorService $twoFactor): bool
    {
        if ($this->confirmsWithSso($user)) {
            if ($this->ssoIsFresh()) {
                return true;
            }

            $this->addError('confirmation', 'Confirm with single sign-on first: sign in through SSO again, then create the token.');

            return false;
        }

        $key = $this->confirmationRateKey($user);

        if (RateLimiter::tooManyAttempts($key, self::CONFIRM_ATTEMPTS)) {
            $this->addError('confirmation', 'Too many wrong attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return false;
        }

        $password = $this->confirmPassword;
        $code = trim($this->confirmCode);

        $valid = ($password !== '' && Hash::check($password, $user->getAuthPassword()))
            || ($code !== '' && $user->hasTwoFactorEnabled() && $twoFactor->verify($user, $code));

        if (! $valid) {
            RateLimiter::hit($key);
            $this->addError('confirmation', $password === '' && $code === ''
                ? 'Enter your password to create a token.'
                : 'That password or code is not right.');

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }
}
