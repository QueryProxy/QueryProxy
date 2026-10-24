<?php

namespace App\Models;

use App\Services\Connections\TlsPemStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'slug'])]
#[Hidden(['masking_key'])]
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
        static::creating(function (Team $team) {
            if (blank($team->masking_key)) {
                $team->masking_key = self::generateMaskingKey();
            }
        });

        static::created(function (Team $team) {
            $team->installDefaultMaskingRules();
        });

        // connections.team_id cascades in the database, which fires no model
        // events, so Connection's own `deleted` hook never runs for these
        // rows. Remove their TLS PEM files (client keys are stored in clear on
        // disk while in use) before the cascade, whichever path deletes the team.
        static::deleting(function (Team $team) {
            $store = app(TlsPemStore::class);

            $team->connections()->pluck('id')->each(fn ($id) => $store->purgeById((int) $id));
        });
    }

    protected function casts(): array
    {
        return [
            'masking_key' => 'encrypted',
        ];
    }

    /**
     * 256-bit random key for the `hmac` masking strategy. The key is never
     * fillable and never serialised (hidden); only Masker reads it.
     */
    public static function generateMaskingKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Replace the team's masking key. Pseudonyms produced before and after no
     * longer match each other, so joins across old and new results break.
     */
    public function rotateMaskingKey(): void
    {
        // Write the ciphertext straight to the row. Going through save()
        // makes Eloquent decrypt the old value for its dirty check, so a key
        // that no longer decrypts (APP_KEY changed without APP_PREVIOUS_KEYS)
        // could not be replaced, which is exactly when rotation is needed.
        static::query()->whereKey($this->getKey())->update([
            'masking_key' => Crypt::encryptString(self::generateMaskingKey()),
        ]);

        $this->refresh();
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
        return $this->installMaskingRules(MaskingRule::defaults());
    }

    /**
     * Install the Turkey PII pack (MaskingRule::turkeyDefaults()) only.
     * Idempotent, like installDefaultMaskingRules().
     *
     * @return int number of rules actually created
     */
    public function installTurkeyMaskingRules(): int
    {
        return $this->installMaskingRules(MaskingRule::turkeyDefaults());
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function installMaskingRules(array $rules): int
    {
        return DB::transaction(function () use ($rules) {
            $created = 0;

            foreach ($rules as $default) {
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
