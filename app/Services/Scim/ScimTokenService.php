<?php

namespace App\Services\Scim;

use App\Enums\AuditEvent;
use App\Models\ScimToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creation and revocation of SCIM tokens (ADR-026); both are audited. */
class ScimTokenService
{
    public const MAX_NAME_LENGTH = 100;

    /**
     * @return array{0: ScimToken, 1: string} the token and its plaintext, which exists only now
     *
     * @throws ValidationException
     */
    public function create(string $name, ?User $creator = null): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'name' => 'The token name must be 1 to '.self::MAX_NAME_LENGTH.' characters.',
            ]);
        }

        $plaintext = ScimToken::generate();

        $token = DB::transaction(function () use ($name, $plaintext, $creator) {
            $token = ScimToken::query()->create([
                'name' => $name,
                'token_hash' => ScimToken::hash($plaintext),
                'created_by' => $creator?->id,
            ]);

            audit()->record(AuditEvent::ScimTokenCreated, actor: $creator, metadata: [
                'token_id' => $token->id,
                'name' => $name,
            ]);

            return $token;
        }, attempts: 3);

        return [$token, $plaintext];
    }

    /** @return bool false when the token is already revoked */
    public function revoke(ScimToken $token, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($token, $actor) {
            // Conditional on still being live, so two revocations cannot both succeed.
            $revoked = ScimToken::query()->whereKey($token->id)->whereNull('revoked_at')->update(['revoked_at' => now()]) === 1;

            if ($revoked) {
                audit()->record(AuditEvent::ScimTokenRevoked, actor: $actor, metadata: [
                    'token_id' => $token->id,
                    'name' => $token->name,
                ]);
            }

            return $revoked;
        }, attempts: 3);
    }

    /** @return Collection<int, ScimToken> */
    public function all(): Collection
    {
        return ScimToken::query()->orderBy('id')->get();
    }
}
