<?php

namespace App\Models;

use App\Enums\GrantAccessLevel;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * One row of connection_user: a developer's access to a connection, with its
 * end time, level and origin (ADR-025). Reached as `->pivot` on the
 * Connection::grantedUsers() relation.
 *
 * @property GrantAccessLevel $access_level
 * @property Carbon|null $expires_at
 */
class ConnectionGrant extends Pivot
{
    protected $table = 'connection_user';

    public $incrementing = true;

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'access_level' => GrantAccessLevel::class,
            'expires_at' => 'datetime',
        ];
    }

    /** A grant without an end time is permanent. */
    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    /** "read access until 2026-10-31 14:00 UTC" or "write access, permanent". */
    public function describe(): string
    {
        return $this->access_level->value.' access'.($this->isPermanent()
            ? ', permanent'
            : ' until '.$this->expires_at->copy()->utc()->format('Y-m-d H:i').' UTC');
    }
}
