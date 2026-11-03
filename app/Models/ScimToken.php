<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer credential for a SCIM client, an identity provider (ADR-026). It is
 * deliberately not a personal access token: it belongs to no user and can only
 * reach /scim/v2. Only the SHA-256 of the value is stored; the plaintext exists
 * once, in the answer to the call that created it.
 */
#[Fillable(['name', 'token_hash', 'created_by', 'last_used_at', 'revoked_at'])]
class ScimToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * A new random value. Plain SHA-256 is enough for the hash: the value is
     * 48 random characters, not something a person chose, so there is nothing
     * for a slow hash to defend against.
     */
    public static function generate(): string
    {
        return 'qpscim_'.Str::random(48);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The live token this plaintext belongs to, or null. */
    public static function findActive(string $plaintext): ?self
    {
        if ($plaintext === '') {
            return null;
        }

        $hash = self::hash($plaintext);

        $token = static::query()->active()->where('token_hash', $hash)->first();

        // The lookup is by hash, so the comparison that matters is already
        // done by the database; this one keeps it constant-time in PHP too.
        return $token !== null && hash_equals($token->token_hash, $hash) ? $token : null;
    }
}
