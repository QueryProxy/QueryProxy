<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function grantedConnections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function roleIn(Team $team): ?TeamRole
    {
        $membership = $this->teams->firstWhere('id', $team->id)
            ?? $this->teams()->whereKey($team->id)->first();

        return $membership ? TeamRole::from($membership->pivot->role) : null;
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->isAdmin() || $this->roleIn($team) !== null;
    }

    public function isDbaIn(Team $team): bool
    {
        return $this->roleIn($team) === TeamRole::Dba;
    }

    public function isDeveloperIn(Team $team): bool
    {
        return $this->roleIn($team) === TeamRole::Developer;
    }

    public function isAuditorIn(Team $team): bool
    {
        return $this->roleIn($team) === TeamRole::Auditor;
    }

    /**
     * Teams the user can operate in: all teams for admins, memberships otherwise.
     */
    public function accessibleTeams()
    {
        return $this->isAdmin() ? Team::query()->orderBy('name')->get() : $this->teams;
    }

    /**
     * The team currently selected in this session.
     */
    public function currentTeam(): ?Team
    {
        $teams = $this->accessibleTeams();

        $current = $teams->firstWhere('id', session('current_team_id'));

        if (! $current && $teams->isNotEmpty()) {
            $current = $teams->first();
            session(['current_team_id' => $current->id]);
        }

        return $current;
    }
}
