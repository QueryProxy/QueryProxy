<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsFreshIdentity;
use App\Models\ScimToken;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Scim\ScimTokenService;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * SCIM settings for system admins (ADR-026): the endpoint URL an identity
 * provider is pointed at, and the creation and revocation of its bearer
 * tokens through ScimTokenService.
 *
 * Like the personal access token screen (ADR-024), creating a token needs a
 * fresh confirmation, and the value is never a component property: create()
 * hands it to the browser in a one-off event, an Alpine block in the view
 * holds it in memory until dismissed, so it is not in the Livewire snapshot,
 * the session or any later render.
 */
#[Title('SCIM')]
class Scim extends Component
{
    use ConfirmsFreshIdentity;

    public string $name = '';

    public ?string $notice = null;

    /**
     * Route middleware only guards the initial page load; every action must
     * re-check because Livewire updates arrive on a separate endpoint and the
     * actor's privileges may have been revoked since the page was served.
     */
    private function assertAdmin(): User
    {
        $user = auth()->user();

        abort_unless($user?->isAdmin(), 403);

        return $user;
    }

    private function scimEnabled(): bool
    {
        return (bool) config('queryproxy.scim.enabled');
    }

    public function mount(): void
    {
        $this->assertAdmin();

        // A refused SSO confirmation redirects back here with an "sso" error in
        // the session (OidcController); a component view only sees its own
        // error bag, so it is carried over.
        $sessionErrors = session('errors');
        $ssoError = $sessionErrors instanceof ViewErrorBag ? $sessionErrors->first('sso') : '';

        if ($ssoError !== '') {
            $this->addError('sso', $ssoError);
        }
    }

    public function create(ScimTokenService $tokens, TwoFactorService $twoFactor): void
    {
        $user = $this->assertAdmin();

        $this->resetErrorBag();
        $this->notice = null;

        if (! $this->scimEnabled()) {
            $this->addError('name', 'SCIM is switched off on this instance, so no token can be created.');
            $this->reset('confirmPassword', 'confirmCode');

            return;
        }

        // A mistake in the form is reported before the confirmation is spent (a TOTP code works once).
        $name = trim($this->name);

        if ($name === '' || mb_strlen($name) > ScimTokenService::MAX_NAME_LENGTH) {
            $this->addError('name', 'The token name must be 1 to '.ScimTokenService::MAX_NAME_LENGTH.' characters.');
            $this->reset('confirmPassword');

            return;
        }

        $confirmed = $this->confirmed($user, $twoFactor);
        $this->reset('confirmPassword', 'confirmCode');

        if (! $confirmed) {
            return;
        }

        try {
            [$token, $plaintext] = $tokens->create($name, $user);
        } catch (ValidationException $e) {
            $this->addError('name', $e->errors()['name'][0] ?? 'The token could not be created.');

            return;
        }

        $this->dispatch('scim-token-created', token: $plaintext, name: $token->name);

        $this->reset('name');
    }

    public function revoke(ScimTokenService $tokens, int $tokenId): void
    {
        $user = $this->assertAdmin();

        $this->notice = null;

        $token = ScimToken::query()->find($tokenId);

        if ($token === null) {
            $this->notice = 'That token is already gone.';

            return;
        }

        $this->notice = $tokens->revoke($token, $user)
            ? 'Token revoked. It no longer works.'
            : 'That token was already revoked.';
    }

    public function render()
    {
        $user = $this->assertAdmin();

        return view('livewire.settings.scim', [
            'enabled' => $this->scimEnabled(),
            'endpoint' => url('/scim/v2'),
            'tokens' => ScimToken::query()->with('creator:id,name')->latest('id')->get(),
            'confirmsWithSso' => $this->confirmsWithSso($user),
            'ssoFresh' => $this->ssoIsFresh(),
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
        ]);
    }
}
