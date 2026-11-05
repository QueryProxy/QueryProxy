<?php

namespace App\Models;

use App\Enums\ChatProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'provider', 'external_id', 'team_id'])]
class ChatIdentity extends Model
{
    /**
     * Slack identities of one workspace. `team_id` is the Slack workspace id
     * (not a QueryProxy team): a Slack member id means something only inside
     * the workspace it was linked in, so an identity without a workspace, or
     * with another one, never matches. A blank workspace matches nothing.
     */
    public function scopeForSlackWorkspace(Builder $query, ?string $workspaceId): Builder
    {
        $query->where('provider', ChatProvider::Slack->value);

        if (blank($workspaceId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('team_id', $workspaceId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
