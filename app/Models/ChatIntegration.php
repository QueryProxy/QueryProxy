<?php

namespace App\Models;

use App\Enums\ChatProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'team_id', 'provider', 'webhook_url', 'signing_secret', 'enabled',
    'bot_token', 'bot_user_id', 'slack_team_id', 'slack_team_name', 'slack_channel_id',
    'dm_approvers', 'installed_by', 'installed_at',
])]
#[Hidden(['webhook_url', 'signing_secret', 'bot_token'])]
class ChatIntegration extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => ChatProvider::class,
            'webhook_url' => 'encrypted',
            'signing_secret' => 'encrypted',
            'bot_token' => 'encrypted',
            'enabled' => 'boolean',
            'dm_approvers' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * Is this a Slack integration the bot can post through: installed, with a
     * channel to post to? Anything else keeps using the incoming webhook.
     */
    public function usesSlackBot(): bool
    {
        return $this->provider === ChatProvider::Slack
            && filled($this->bot_token)
            && filled($this->slack_channel_id);
    }

    /**
     * Forget the installation after Slack revoked it (app_uninstalled,
     * tokens_revoked) or refused the token. The webhook mode, if configured,
     * is left alone.
     */
    public function clearSlackInstallation(): void
    {
        $this->forceFill([
            'bot_token' => null,
            'bot_user_id' => null,
            'slack_team_id' => null,
            'slack_team_name' => null,
            'slack_channel_id' => null,
            'installed_by' => null,
            'installed_at' => null,
        ])->save();
    }

    public function scopeEnabledFor(Builder $query, ChatProvider $provider): Builder
    {
        return $query->where('provider', $provider)->where('enabled', true);
    }

    /**
     * Constant-time check used when rotating the secret: whoever replaces it
     * has to prove they already hold the one in place.
     */
    public function matchesSigningSecret(string $candidate): bool
    {
        return hash_equals((string) $this->signing_secret, $candidate);
    }
}
