<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Slack member that users.lookupByEmail matched to a QueryProxy user without
 * a chat identity. It is only a proposal: an admin confirms or dismisses it in
 * the web UI, and nothing is linked automatically (ADR-027).
 */
#[Fillable(['team_id', 'user_id', 'slack_team_id', 'slack_user_id', 'matched_email', 'status'])]
class SlackIdentitySuggestion extends Model
{
    public const PENDING = 'pending';

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
