<?php

namespace App\Models;

use App\Enums\DbDriver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'team_id', 'name', 'driver', 'host', 'port', 'database',
    'username', 'password', 'options', 'created_by',
])]
class Connection extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'driver' => DbDriver::class,
            'host' => 'encrypted',
            'port' => 'encrypted',
            'database' => 'encrypted',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'options' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Developers explicitly granted access to this connection. */
    public function grantedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function queryRequests(): HasMany
    {
        return $this->hasMany(QueryRequest::class);
    }

    public function maskingRules(): HasMany
    {
        return $this->hasMany(MaskingRule::class);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    public function isGrantedTo(User $user): bool
    {
        return $this->grantedUsers()->whereKey($user->id)->exists();
    }
}
