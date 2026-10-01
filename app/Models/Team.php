<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'slug'])]
class Team extends Model
{
    use HasFactory;

    /**
     * Every team starts protected: the default masking rules are installed
     * the moment a team row is created, whichever path creates it (admin UI,
     * seeder, factory). Teams that existed before this hook are deliberately
     * not backfilled — a DBA installs the defaults from the masking page.
     */
    protected static function booted(): void
    {
        static::created(function (Team $team) {
            $team->installDefaultMaskingRules();
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function maskingRules(): HasMany
    {
        return $this->hasMany(MaskingRule::class);
    }

    /**
     * Install MaskingRule::defaults() for this team. Idempotent: a default is
     * matched on (match_type, pattern), so running it again — or after a DBA
     * kept some of the defaults — never duplicates a rule. All-or-nothing:
     * a failure part-way rolls back every rule this call added, so a team is
     * never left with a silently partial default set.
     *
     * @return int number of rules actually created
     */
    public function installDefaultMaskingRules(): int
    {
        return DB::transaction(function () {
            $created = 0;

            foreach (MaskingRule::defaults() as $default) {
                $rule = MaskingRule::firstOrCreate(
                    ['team_id' => $this->id, 'pattern' => $default['pattern'], 'match_type' => $default['match_type']],
                    $default,
                );

                if ($rule->wasRecentlyCreated) {
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * True when the team has at least one enabled rule, at any scope.
     *
     * This is a team-level signal only: Masker narrows rules per connection
     * (team-wide rules plus those bound to the queried connection), so a team
     * whose only enabled rule is bound to connection A still gets unmasked
     * results on connection B while this returns true.
     */
    public function hasEnabledMaskingRules(): bool
    {
        return $this->maskingRules()->where('enabled', true)->exists();
    }
}
