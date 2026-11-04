<?php

namespace App\Models;

use App\Enums\MailEvent;
use App\Enums\TeamRole;
use App\Support\SqlServerReferentialActions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_OIDC = 'oidc';

    protected static function booted(): void
    {
        // SQL Server only: the keys that would SET NULL on a hard delete cannot
        // do so there (more than one cascade path), so it is done here. Users
        // are soft deleted in normal use (ADR-018); this is for forceDelete().
        static::forceDeleting(function (User $user) {
            if (SqlServerReferentialActions::isSqlServer($user->getConnection())) {
                SqlServerReferentialActions::releaseUserReferences($user->getConnection(), $user->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'scim_provisioned_at' => 'datetime',
            'suspended_by_scim' => 'boolean',
            'notification_preferences' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_timestamp' => 'integer',
        ];
    }

    /**
     * True when the identity provider owns the account: it is tied to the
     * OpenID Connect provider (users.auth_provider), or the provider created
     * it through SCIM and it has not signed in yet (ADR-026). Such an account
     * is never given a local password.
     */
    public function isSsoUser(): bool
    {
        return $this->auth_provider === self::PROVIDER_OIDC || $this->isScimManaged();
    }

    /** True when the account was created through SCIM (users.scim_provisioned_at). */
    public function isScimManaged(): bool
    {
        return $this->scim_provisioned_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Users who may sign in and act: neither suspended nor soft-deleted. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('suspended_at'));
    }

    /** True when the user turned on e-mail for this event; off by default. */
    public function wantsMail(MailEvent $event): bool
    {
        return ($this->notification_preferences[$event->value] ?? false) === true;
    }

    /**
     * Every event with the user's choice, in catalog order.
     *
     * @return array<string, bool>
     */
    public function mailPreferences(): array
    {
        $preferences = [];

        foreach (MailEvent::cases() as $event) {
            $preferences[$event->value] = $this->wantsMail($event);
        }

        return $preferences;
    }

    /**
     * Stores the choices; keys outside the catalog are dropped.
     *
     * @param  array<string, mixed>  $preferences
     */
    public function setMailPreferences(array $preferences): void
    {
        $stored = [];

        foreach (MailEvent::cases() as $event) {
            // The values the `boolean` validation rule accepts as true.
            $stored[$event->value] = in_array($preferences[$event->value] ?? false, [true, 1, '1'], true);
        }

        $this->forceFill(['notification_preferences' => $stored])->save();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** True when the user holds the DBA role in at least one team. */
    public function isDbaAnywhere(): bool
    {
        return $this->teams()->wherePivot('role', TeamRole::Dba->value)->exists();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Connections the user holds a grant for that is still in force: permanent,
     * or not yet expired (ADR-025), as Connection::grantedUsers() counts them.
     */
    public function grantedConnections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class)
            ->using(ConnectionGrant::class)
            ->withPivot(['expires_at', 'granted_by', 'reason', 'access_level'])
            ->withTimestamps()
            ->where(fn ($query) => $query
                ->whereNull('connection_user.expires_at')
                ->orWhere('connection_user.expires_at', '>', now()));
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
