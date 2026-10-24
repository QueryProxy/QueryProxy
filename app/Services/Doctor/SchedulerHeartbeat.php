<?php

namespace App\Services\Doctor;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A timestamp the scheduler writes every minute (routes/console.php), so
 * queryproxy:doctor can tell a running scheduler from a stopped one.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'queryproxy:scheduler:heartbeat';

    /** Seconds without a beat before the scheduler counts as stopped (three missed runs). */
    public const STALE_AFTER = 180;

    public static function beat(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), self::STALE_AFTER * 5);
    }

    /** Seconds since the last beat, or null when there is none (or the cache is unreadable). */
    public static function ageInSeconds(): ?int
    {
        try {
            $beat = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($beat) ? max(0, now()->getTimestamp() - (int) $beat) : null;
    }
}
