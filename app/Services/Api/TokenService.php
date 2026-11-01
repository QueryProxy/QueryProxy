<?php

namespace App\Services\Api;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates and revokes the personal access tokens of the REST API (ADR-024).
 *
 * A token belongs to one user, carries a fixed set of scopes and always
 * expires. Both operations are audited; the token value itself is shown to its
 * owner once, in the NewAccessToken this returns, and is never stored in
 * plain text or written to the audit log or the application log.
 */
class TokenService
{
    public const SCOPE_REQUESTS_SUBMIT = 'requests:submit';

    public const SCOPE_REQUESTS_READ = 'requests:read';

    public const SCOPE_RESULTS_READ = 'results:read';

    public const SCOPE_APPROVALS_WRITE = 'approvals:write';

    public const MAX_NAME_LENGTH = 100;

    /** @return list<string> */
    public static function scopes(): array
    {
        return [
            self::SCOPE_REQUESTS_SUBMIT,
            self::SCOPE_REQUESTS_READ,
            self::SCOPE_RESULTS_READ,
            self::SCOPE_APPROVALS_WRITE,
        ];
    }

    /** The longest lifetime a token can be given, in days (QUERYPROXY_API_TOKEN_MAX_DAYS). */
    public static function maxDays(): int
    {
        return max(1, (int) config('queryproxy.api.token_max_days', 90));
    }

    /**
     * @param  list<string>  $scopes  at least one of self::scopes()
     * @param  int  $days  lifetime, 1 to self::maxDays()
     *
     * @throws ValidationException when the name, the scopes or the lifetime are not acceptable
     * @throws AuthorizationException when the user is suspended
     */
    public function create(User $user, string $name, array $scopes, int $days): NewAccessToken
    {
        $name = trim($name);
        $scopes = array_values(array_unique($scopes));

        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'name' => 'The token name must be 1 to '.self::MAX_NAME_LENGTH.' characters.',
            ]);
        }

        if ($scopes === [] || array_diff($scopes, self::scopes()) !== []) {
            throw ValidationException::withMessages([
                'scopes' => 'Choose at least one of: '.implode(', ', self::scopes()).'.',
            ]);
        }

        if ($days < 1 || $days > self::maxDays()) {
            throw ValidationException::withMessages([
                'expires_in_days' => 'A token lasts 1 to '.self::maxDays().' days.',
            ]);
        }

        if ($user->isSuspended()) {
            throw new AuthorizationException('A suspended account cannot create tokens.');
        }

        return DB::transaction(function () use ($user, $name, $scopes, $days) {
            $token = $user->createToken($name, $scopes, now()->addDays($days));

            audit()->record(AuditEvent::TokenCreated, actor: $user, metadata: [
                'token_id' => $token->accessToken->id,
                'name' => $name,
                'scopes' => $scopes,
                'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            ]);

            return $token;
        });
    }

    /**
     * Revoke one of the user's own tokens. Another user's token id is
     * indistinguishable from a missing one.
     *
     * @throws ModelNotFoundException
     */
    public function revoke(User $user, int $tokenId): void
    {
        DB::transaction(function () use ($user, $tokenId) {
            /** @var PersonalAccessToken $token */
            $token = $user->tokens()->whereKey($tokenId)->lockForUpdate()->firstOrFail();

            $token->delete();

            audit()->record(AuditEvent::TokenRevoked, actor: $user, metadata: [
                'token_id' => $token->id,
                'name' => $token->name,
            ]);
        });
    }

    /**
     * Revoke every token of a user whose account was suspended or deleted. The
     * audit record's actor is whoever is signed in (the administrator), or
     * nobody when it happens outside a request.
     *
     * @param  string  $reason  user_suspended | user_deleted
     * @return int how many tokens were revoked
     */
    public function revokeAll(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason) {
            $tokens = $user->tokens()->get();

            foreach ($tokens as $token) {
                $token->delete();

                audit()->record(AuditEvent::TokenRevoked, metadata: [
                    'token_id' => $token->id,
                    'name' => $token->name,
                    'owner_id' => $user->id,
                    'reason' => $reason,
                ]);
            }

            return $tokens->count();
        });
    }
}
