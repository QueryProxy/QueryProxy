<?php

namespace App\Livewire\Profile;

use App\Enums\TeamRole;
use App\Http\Controllers\Auth\OidcController;
use App\Models\User;
use App\Services\Api\TokenService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;

/**
 * The "API tokens" section of the profile page (ADR-024): create and revoke
 * the signed-in user's personal access tokens through TokenService.
 *
 * The token value is never a component property. create() hands it to the
 * browser in a one-off event; an Alpine block in the view holds it in memory
 * until the user dismisses it, so it is not in the Livewire snapshot, not in
 * the session and not in any later render or page load.
 */
class ApiTokens extends Component
{
    /** How recent an SSO sign-in must be to create a token without a local password (ADR-024). */
    public const SSO_FRESH_MINUTES = 15;

    /** Wrong confirmations allowed per minute before the user must wait. */
    private const CONFIRM_ATTEMPTS = 5;

    public string $name = '';

    /** Kept as a string so an emptied number input does not break the typed property. */
    public string $days = '';

    /** @var list<string> */
    public array $scopes = [];

    /** Fresh confirmation (ADR-024): the local password, or a code from the authenticator app. */
    public string $confirmPassword = '';

    public string $confirmCode = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->days = (string) min(30, TokenService::maxDays());
    }

    /**
     * The scopes this user may put on a token. A scope only narrows what the
     * owner can do (ADR-024), so a scope the owner's roles could never use is
     * not offered, and create() refuses it even if a client sends it.
     *
     * @return list<string>
     */
    public static function allowedScopes(User $user): array
    {
        $roles = $user->teams()->pluck('team_user.role')->all();
        $isDba = in_array(TeamRole::Dba->value, $roles, true);
        $canFile = $isDba || in_array(TeamRole::Developer->value, $roles, true);

        return array_values(array_filter(TokenService::scopes(), fn (string $scope) => match ($scope) {
            TokenService::SCOPE_REQUESTS_READ => true,
            TokenService::SCOPE_REQUESTS_SUBMIT => $canFile,
            // Auditors get no result data (ADR-009); admins and requesters do.
            TokenService::SCOPE_RESULTS_READ => $canFile || $user->isAdmin(),
            // Approving needs the DBA role of a team; an admin who is not a DBA can only override on the web.
            TokenService::SCOPE_APPROVALS_WRITE => $isDba,
            default => false,
        }));
    }

    /**
     * Covered by the 2FA requirement and not enrolled yet: no token until they
     * are (ADR-016). Asked as the API asks it (no session): a token is used
     * without the session it was made in, so this session being an SSO one
     * must not make the screen more lenient than the API.
     */
    private function mustEnrol(User $user, TwoFactorService $twoFactor): bool
    {
        return $twoFactor->mustEnrol($user, null);
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

    /**
     * Is the confirmation good? Wrong tries are counted per user, so a stolen
     * session cannot be used to guess the password.
     */
    private function confirmed(User $user, TwoFactorService $twoFactor): bool
    {
        if ($this->confirmsWithSso($user)) {
            if ($this->ssoIsFresh()) {
                return true;
            }

            $this->addError('confirmation', 'Confirm with single sign-on first: sign in through SSO again, then create the token.');

            return false;
        }

        $key = 'api-token-confirm:'.$user->id;

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

    public function create(TokenService $tokens, TwoFactorService $twoFactor): void
    {
        $user = auth()->user();

        $this->resetErrorBag();
        $this->notice = null;

        if ($this->mustEnrol($user, $twoFactor)) {
            $this->addError('confirmation', 'Set up two-factor authentication first: your instance requires it for your role.');
            $this->reset('confirmPassword', 'confirmCode');

            return;
        }

        $scopes = array_values(array_unique($this->scopes));
        $days = ctype_digit(trim($this->days)) ? (int) trim($this->days) : 0;

        if (array_diff($scopes, self::allowedScopes($user)) !== []) {
            $this->addError('scopes', 'Your roles do not allow one of the chosen scopes.');

            return;
        }

        // Mistakes in the form are reported before the confirmation is spent
        // (a TOTP code works once). TokenService still decides on its own.
        $name = trim($this->name);

        if ($name === '' || mb_strlen($name) > TokenService::MAX_NAME_LENGTH) {
            $this->addError('name', 'The token name must be 1 to '.TokenService::MAX_NAME_LENGTH.' characters.');
        }
        if ($scopes === []) {
            $this->addError('scopes', 'Choose at least one scope.');
        }
        if ($days < 1 || $days > TokenService::maxDays()) {
            $this->addError('days', 'A token lasts 1 to '.TokenService::maxDays().' days.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            $this->reset('confirmPassword');

            return;
        }

        $confirmed = $this->confirmed($user, $twoFactor);
        $this->reset('confirmPassword', 'confirmCode');

        if (! $confirmed) {
            return;
        }

        try {
            $token = $tokens->create($user, $this->name, $scopes, $days);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'expires_in_days' ? 'days' : $field, $messages[0]);
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->dispatch('api-token-created', token: $token->plainTextToken, name: $token->accessToken->name);

        $this->reset('name', 'scopes');
        $this->days = (string) min(30, TokenService::maxDays());
    }

    public function revoke(TokenService $tokens, int $tokenId): void
    {
        $this->notice = null;

        try {
            $tokens->revoke(auth()->user(), $tokenId);
            $this->notice = 'Token revoked. It no longer works.';
        } catch (ModelNotFoundException) {
            $this->notice = 'That token is already gone.';
        }
    }

    /**
     * When the token stops working: its own expiry, or the age limit that
     * applies to every token, whichever comes first.
     */
    private function effectiveExpiry(PersonalAccessToken $token): ?Carbon
    {
        $limit = $token->created_at?->copy()->addMinutes((int) config('sanctum.expiration'));

        if ($token->expires_at === null) {
            return $limit;
        }

        return $limit !== null && $limit->lt($token->expires_at) ? $limit : $token->expires_at;
    }

    public function render(TwoFactorService $twoFactor)
    {
        $user = auth()->user();

        $rows = $user->tokens()->latest('id')->get()->map(fn (PersonalAccessToken $token) => [
            'id' => $token->id,
            'name' => $token->name,
            'scopes' => $token->abilities ?? [],
            'last_used_at' => $token->last_used_at,
            'expires_at' => $this->effectiveExpiry($token),
        ]);

        return view('livewire.profile.api-tokens', [
            'tokens' => $rows,
            'allowedScopes' => self::allowedScopes($user),
            'maxDays' => TokenService::maxDays(),
            'enrolFirst' => $this->mustEnrol($user, $twoFactor),
            'confirmsWithSso' => $this->confirmsWithSso($user),
            'ssoFresh' => $this->ssoIsFresh(),
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'scopeLabels' => [
                TokenService::SCOPE_REQUESTS_READ => 'Read requests and list your connections',
                TokenService::SCOPE_REQUESTS_SUBMIT => 'Submit query requests and withdraw your own',
                TokenService::SCOPE_RESULTS_READ => 'Download results of requests you may view',
                TokenService::SCOPE_APPROVALS_WRITE => 'Approve and reject requests as a DBA (never your own, never an override)',
            ],
        ]);
    }
}
