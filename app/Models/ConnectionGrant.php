<?php

namespace App\Models;

use App\Enums\GrantAccessLevel;
use Carbon\CarbonInterface;
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

    /**
     * Time left as "3h 59m", "2d 4h" or "<1m"; null for a permanent grant.
     * Minutes are the finest unit: the page is not refreshed live, so seconds
     * would only look exact.
     */
    public function remaining(): ?string
    {
        if ($this->expires_at === null) {
            return null;
        }

        if ($this->expires_at->lte(now()->addMinute())) {
            return '<1m';
        }

        return $this->expires_at->diffForHumans([
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'short' => true,
            'parts' => 2,
            'minimumUnit' => 'minute',
        ]);
    }

    /** "read access until 2026-10-31 14:00 UTC" or "write access, permanent". */
    public function describe(): string
    {
        return $this->access_level->value.' access'.($this->isPermanent()
            ? ', permanent'
            : ' until '.$this->expires_at->copy()->utc()->format('Y-m-d H:i').' UTC');
    }
}
