<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * Reads a secret from `NAME` or, Docker/Kubernetes secret style, from the file
 * named by `NAME_FILE`.
 *
 * Defining both is a configuration error and throws, so a stale value can never
 * silently win over the mounted secret. The file must exist, be readable and
 * not be empty; trailing line breaks (CR/LF) are stripped, anything else is kept
 * verbatim. docker/entrypoint.sh (docker/secret-files.sh) applies the same rule
 * to the same variables, so the shell and PHP agree.
 *
 * A NAME_FILE path is taken verbatim on both sides; only NAME itself follows
 * env() (see environment()).
 *
 * Messages name the variable and the path, never the value.
 */
final class SecretFile
{
    /**
     * Variables that can be supplied through NAME_FILE. Keep in sync with
     * SECRET_FILE_VARIABLES in docker/secret-files.sh. Not every one is read by
     * PHP config: QUERYPROXY_ADMIN_PASSWORD is used by the entrypoint only, and
     * stays listed so inspect() (queryproxy:doctor) reports its *_FILE too.
     *
     * @var list<string>
     */
    public const VARIABLES = [
        'APP_KEY',
        'APP_PREVIOUS_KEYS',
        'DB_PASSWORD',
        'MAIL_PASSWORD',
        'REDIS_PASSWORD',
        'SLACK_BOT_USER_OAUTH_TOKEN',
        'QUERYPROXY_ADMIN_PASSWORD',
        'QUERYPROXY_METRICS_TOKEN',
        'QUERYPROXY_SIEM_WEBHOOK_SECRET',
    ];

    /**
     * @param  Closure(string): (string|null)|null  $lookup  environment reader (defaults to env() semantics, see environment(); tests inject one)
     *
     * @throws RuntimeException when both NAME and NAME_FILE are set, or the file cannot be used
     */
    public static function get(string $name, ?string $default = null, ?Closure $lookup = null): ?string
    {
        $value = ($lookup ?? self::environment(...))($name);
        $file = ($lookup ?? self::rawEnvironment(...))($name.'_FILE');

        $hasValue = is_string($value) && $value !== '';
        $hasFile = is_string($file) && $file !== '';

        if ($hasValue && $hasFile) {
            throw new RuntimeException("Both {$name} and {$name}_FILE are set; define only one of them.");
        }

        if ($hasFile) {
            return self::read($name.'_FILE', $file);
        }

        return $hasValue ? $value : $default;
    }

    /**
     * Problems with the *_FILE variables, without throwing: one line per
     * variable that is in conflict or points at an unusable file. Used by
     * queryproxy:doctor.
     *
     * @param  Closure(string): (string|null)|null  $lookup
     * @return array{in_use: list<string>, problems: list<string>}
     */
    public static function inspect(?Closure $lookup = null): array
    {
        $in_use = [];
        $problems = [];

        foreach (self::VARIABLES as $name) {
            try {
                $file = ($lookup ?? self::rawEnvironment(...))($name.'_FILE');

                if (is_string($file) && $file !== '') {
                    self::get($name, null, $lookup);
                    $in_use[] = $name;
                }
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return ['in_use' => $in_use, 'problems' => $problems];
    }

    private static function read(string $variable, string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("{$variable} points at '{$path}', which is not a readable file.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("{$variable} points at '{$path}', which could not be read.");
        }

        $contents = rtrim($contents, "\r\n");

        if ($contents === '') {
            throw new RuntimeException("{$variable} points at '{$path}', which is empty.");
        }

        return $contents;
    }

    /**
     * A NAME_FILE path exactly as written, the way docker/secret-files.sh reads
     * it: no env() placeholders ("null" is a file called null) and no quote
     * stripping (a .env file's own quotes are already removed by the parser).
     */
    private static function rawEnvironment(string $name): ?string
    {
        $value = Env::getRepository()->get($name);

        return is_string($value) ? $value : null;
    }

    /**
     * The value env() would give: "null" / "(null)" mean not set and "(empty)"
     * means empty, so a `.env` line such as `REDIS_PASSWORD=null` (the Laravel
     * skeleton's way of saying "no password") still counts as unset instead of
     * becoming the literal password "null". The one deliberate difference:
     * "true" / "false" stay the text the operator wrote, because a secret is
     * always a string.
     */
    private static function environment(string $name): ?string
    {
        $value = Env::get($name);

        if (is_bool($value)) {
            $raw = Env::getRepository()->get($name);

            return is_string($raw) ? $raw : null;
        }

        return is_string($value) ? $value : null;
    }
}
