<?php

namespace App\Services\Auth;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds, links or creates the QueryProxy user for a validated ID token
 * (ADR-023). The identity is the pair issuer + subject, never the e-mail alone:
 *
 *  - a known issuer + subject signs in to its account;
 *  - an unknown one whose e-mail belongs to a local account is linked to it
 *    only when the provider asserts email_verified = true (audited);
 *  - an unknown one with an unknown e-mail gets a new account, again only
 *    when the provider asserts email_verified = true (audited).
 *
 * A refused unverified address is audited (auth.sso_refused). A soft-deleted
 * or suspended account is never revived, linked or re-created, and an e-mail
 * held by an account tied to another identity is never taken over.
 */
class OidcUserProvisioner
{
    /**
     * @param  array<string, mixed>  $claims  validated ID token claims
     *
     * @throws OidcException
     */
    public function resolve(array $claims, string $issuer): User
    {
        $subject = (string) $claims['sub'];

        try {
            return $this->resolveOnce($claims, $subject, $issuer);
        } catch (UniqueConstraintViolationException) {
            // Two first logins of the same person raced; the other one won.
            return $this->resolveOnce($claims, $subject, $issuer);
        }
    }

    private function resolveOnce(array $claims, string $subject, string $issuer): User
    {
        $known = User::withTrashed()
            ->where('auth_provider', User::PROVIDER_OIDC)
            ->where('external_issuer', $issuer)
            ->where('external_id', $subject)
            ->first();

        if ($known) {
            return $this->active($known);
        }

        $email = $claims['email'] ?? null;

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new OidcException('The ID token carries no usable e-mail address, so no account can be matched or created.');
        }

        $sameEmail = User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if ($sameEmail) {
            // Deleted and suspended accounts are refused before anything is written to them.
            return $this->link($this->active($sameEmail), $claims, $subject, $issuer);
        }

        return $this->create($claims, $email, $subject, $issuer);
    }

    /** @throws OidcException */
    private function active(User $user): User
    {
        if ($user->trashed()) {
            throw new OidcException("User {$user->id} is deleted and cannot sign in through SSO.");
        }

        if ($user->isSuspended()) {
            throw new OidcException("User {$user->id} is suspended.", __('This account is suspended.'));
        }

        return $user;
    }

    /** @throws OidcException */
    private function link(User $user, array $claims, string $subject, string $issuer): User
    {
        if ($user->auth_provider !== User::PROVIDER_LOCAL) {
            // Already tied to another identity: the e-mail alone proves nothing.
            throw new OidcException("User {$user->id} is tied to a different identity provider subject.");
        }

        if (! $this->emailIsVerified($claims)) {
            $this->refuseUnverified('link_unverified_email', $user->email, $issuer, "The e-mail of user {$user->id} matches, but the provider does not assert it as verified; account not linked.");
        }

        DB::transaction(function () use ($user, $subject, $issuer) {
            $user->forceFill([
                'auth_provider' => User::PROVIDER_OIDC,
                'external_issuer' => $issuer,
                'external_id' => $subject,
            ])->save();

            audit()->record(AuditEvent::UserSsoLinked, actor: $user, metadata: [
                'user' => $user->email,
                'issuer' => $issuer,
            ]);
        }, attempts: 3);

        return $user;
    }

    private function create(array $claims, string $email, string $subject, string $issuer): User
    {
        if (! $this->emailIsVerified($claims)) {
            $this->refuseUnverified('provision_unverified_email', $email, $issuer, 'The provider does not assert the e-mail address as verified; no account created.');
        }

        $name = collect([$claims['name'] ?? null, $claims['preferred_username'] ?? null])
            ->first(fn ($candidate) => is_string($candidate) && trim($candidate) !== '')
            ?? Str::before($email, '@');

        return DB::transaction(function () use ($email, $name, $subject, $issuer) {
            $user = new User;
            $user->forceFill([
                'name' => Str::limit(trim($name), 255, ''),
                'email' => $email,
                'password' => null,
                'is_admin' => false,
                'auth_provider' => User::PROVIDER_OIDC,
                'external_issuer' => $issuer,
                'external_id' => $subject,
                'email_verified_at' => now(),
            ])->save();

            audit()->record(AuditEvent::UserSsoProvisioned, actor: $user, metadata: [
                'user' => $user->email,
                'issuer' => $issuer,
            ]);

            return $user;
        }, attempts: 3);
    }

    /**
     * An address the provider does not vouch for must neither be linked to an
     * account nor become one: an account is found by e-mail elsewhere in the
     * application (adding a team member), so an unverified address could be
     * claimed by someone who does not own it.
     *
     * @throws OidcException
     */
    private function refuseUnverified(string $reason, string $email, string $issuer, string $logMessage): never
    {
        audit()->record(AuditEvent::AuthSsoRefused, metadata: [
            'reason' => $reason,
            'user' => $email,
            'issuer' => $issuer,
        ]);

        throw new OidcException($logMessage);
    }

    /**
     * Does the provider vouch for the e-mail address? `email_verified` decides
     * when it is there: a JSON boolean, or the string "true" some providers
     * send; any other value, "false" included, means not verified. Entra ID
     * never sends it, so only when it is absent does `xms_edov` (Entra's "email
     * domain owner verified") count, with the same two accepted spellings of
     * true. An explicit `email_verified` is never overridden by `xms_edov`.
     */
    private function emailIsVerified(array $claims): bool
    {
        $claim = $claims['email_verified'] ?? $claims['xms_edov'] ?? null;

        return $claim === true || $claim === 'true';
    }
}
