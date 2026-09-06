<?php

namespace App\Models;

use App\Enums\ChatProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'provider', 'webhook_url', 'signing_secret', 'enabled'])]
class ChatIntegration extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => ChatProvider::class,
            'webhook_url' => 'encrypted',
            'signing_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeEnabledFor(Builder $query, ChatProvider $provider): Builder
    {
        return $query->where('provider', $provider)->where('enabled', true);
    }
}
