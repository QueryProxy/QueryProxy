<?php

namespace App\Support;

/**
 * Resolves QUERYPROXY_EXECUTION_TIMEOUT to a usable number of seconds.
 *
 * A valid value is a whole number of seconds greater than zero, written with
 * digits only. Anything else (text, empty, 0, negative, decimal) falls back to
 * DEFAULT. docker/entrypoint.sh applies the same rule when it derives the
 * worker timings, so the app and the worker never run with different timeouts.
 *
 * The raw value is taken exactly as env() returns it: env() turns the strings
 * "true", "false", "null" and "(empty)" into a bool, null or '', none of which
 * the entrypoint would accept as a number, so only a real string of digits
 * counts here.
 */
final class ExecutionTimeout
{
    public const DEFAULT = 300;

    public static function resolve(mixed $raw): int
    {
        if (! is_string($raw) || ! ctype_digit($raw)) {
            return self::DEFAULT;
        }

        $seconds = (int) $raw;

        return $seconds > 0 ? $seconds : self::DEFAULT;
    }
}
