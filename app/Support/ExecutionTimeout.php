<?php

namespace App\Support;

/**
 * Resolves QUERYPROXY_EXECUTION_TIMEOUT to a usable number of seconds.
 *
 * A valid value is a whole number of seconds greater than zero, written with
 * digits only. Anything else (text, empty, 0, negative, decimal) falls back to
 * DEFAULT. A valid value above MAX (24 hours) is clamped to MAX, however many
 * digits it has: the digit string is compared before any integer cast, so a
 * number beyond PHP_INT_MAX cannot saturate or overflow. docker/entrypoint.sh
 * applies the same rule when it derives the worker timings, so the app and the
 * worker never run with different timeouts.
 *
 * The raw value is taken exactly as env() returns it: env() turns the strings
 * "true", "false", "null" and "(empty)" into a bool, null or '', none of which
 * the entrypoint would accept as a number, so only a real string of digits
 * counts here.
 */
final class ExecutionTimeout
{
    public const DEFAULT = 300;

    public const MAX = 86400;

    /** Seconds a statement waits for a lock on the target database by default. */
    public const LOCK_DEFAULT = 5;

    /**
     * Upper bound for the lock timeout: one hour, far below the point where
     * the millisecond value would overflow a 32-bit server setting.
     */
    public const LOCK_MAX = 3600;

    public static function resolve(mixed $raw): int
    {
        return self::resolveBounded($raw, self::DEFAULT, self::MAX);
    }

    /** QUERYPROXY_LOCK_TIMEOUT, resolved by the same rule as the execution timeout. */
    public static function resolveLock(mixed $raw): int
    {
        return self::resolveBounded($raw, self::LOCK_DEFAULT, self::LOCK_MAX);
    }

    private static function resolveBounded(mixed $raw, int $default, int $max): int
    {
        if (! is_string($raw) || ! ctype_digit($raw)) {
            return $default;
        }

        $digits = ltrim($raw, '0');

        if ($digits === '') {
            return $default;
        }

        if (strlen($digits) > strlen((string) $max)) {
            return $max;
        }

        return min((int) $digits, $max);
    }
}
