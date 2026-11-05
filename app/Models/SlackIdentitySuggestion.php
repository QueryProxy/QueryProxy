<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Slack member that users.lookupByEmail matched to a QueryProxy user without
 * a chat identity. It is only a proposal: an admin confirms or dismisses it in
 * the web UI, and nothing is linked automatically (ADR-027). A dismissed
 * suggestion is kept with its dismissed_at time so the scan does not propose
 * the same match again.
 */
#[Fillable(['team_id', 'user_id', 'slack_team_id', 'slack_user_id', 'matched_email', 'status', 'dismissed_at'])]
class SlackIdentitySuggestion extends Model
{
    public const PENDING = 'pending';

    /** Set together with dismissed_at when an admin rejects the suggestion. */
    public const DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    /** Suggestions an admin still has to act on: not yet dismissed. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->whereNull('dismissed_at');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
