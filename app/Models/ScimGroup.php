<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group the identity provider pushed through SCIM (ADR-026). QueryProxy has
 * no groups of its own: the display name only matters because
 * QUERYPROXY_OIDC_ROLE_MAP may name it. A group the map does not name is stored
 * and has no effect.
 */
#[Fillable(['display_name', 'external_id'])]
class ScimGroup extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'scim_group_user')->withTimestamps();
    }
}
