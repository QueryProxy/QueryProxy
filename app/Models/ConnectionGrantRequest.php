<?php

namespace App\Models;

use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A developer's request for time-bound access to a connection (ADR-025).
 * Decided by a DBA of the connection's team through GrantService.
 */
#[Fillable([
    'team_id', 'connection_id', 'user_id', 'access_level', 'duration_minutes', 'reason',
    'status', 'decided_by', 'decided_at', 'decision_note',
])]
class ConnectionGrantRequest extends Model
{
    protected function casts(): array
    {
        return [
            'access_level' => GrantAccessLevel::class,
            'status' => GrantRequestStatus::class,
            'duration_minutes' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', GrantRequestStatus::Pending);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    public function isPending(): bool
    {
        return $this->status === GrantRequestStatus::Pending;
    }

    /** "4 hours", "1 day 2 hours", "30 minutes". */
    public function durationLabel(): string
    {
        return CarbonInterval::minutes($this->duration_minutes)->cascade()->forHumans(['parts' => 2]);
    }
}
