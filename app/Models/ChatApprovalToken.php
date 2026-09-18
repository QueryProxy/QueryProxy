<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use nonce bound to one query request, embedded in the Approve /
 * Reject controls of the chat message we send out.
 *
 * It deliberately does *not* identify the approver: an incoming webhook only
 * reaches a channel, never a DM, so one message is seen by every DBA in the
 * room and a per-user token would be meaningless. What the token proves is
 * that the callback answers a message QueryProxy actually posted, and that it
 * has not been answered before — so holding the signing secret alone is no
 * longer enough to forge a decision out of thin air or to replay a captured
 * one.
 */
#[Fillable(['query_request_id', 'token_hash', 'expires_at', 'used_at'])]
class ChatApprovalToken extends Model
{
    /**
     * How long a chat approval control stays usable. Past this window the
     * decision belongs in the web UI, where the actor is authenticated.
     */
    public const LIFETIME_HOURS = 24;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function queryRequest(): BelongsTo
    {
        return $this->belongsTo(QueryRequest::class);
    }

    /**
     * Mint a token for the request and return its plaintext, which is the only
     * moment the plaintext exists on our side.
     */
    public static function issueFor(QueryRequest $request): string
    {
        $token = Str::random(40);

        static::create([
            'query_request_id' => $request->id,
            'token_hash' => static::hash($token),
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);

        return $token;
    }

    /**
     * A plain SHA-256 is enough: this is a 40-character random nonce with a
     * short life, not a user-chosen credential, so there is nothing for a
     * slow hash to defend against.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Is this token live, unused and bound to exactly this request? */
    public static function isValidFor(QueryRequest $request, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return static::query()
            ->where('query_request_id', $request->id)
            ->where('token_hash', static::hash($token))
            ->where('expires_at', '>', now())
            ->whereNull('used_at')
            ->exists();
    }

    /**
     * Burn the token. The update is conditional on it still being unused, so
     * two callbacks racing on the same message cannot both claim it — the
     * loser gets false and is turned away.
     */
    public static function consume(QueryRequest $request, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return static::query()
            ->where('query_request_id', $request->id)
            ->where('token_hash', static::hash($token))
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }
}
