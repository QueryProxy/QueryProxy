<?php

namespace App\Services\Scim;

/** Reading values out of a SCIM request body, tolerant of what the identity providers actually send. */
final class ScimValue
{
    /**
     * A boolean. Entra ID sends "True" and "False" as strings.
     *
     * @throws ScimException
     */
    public static function bool(mixed $value, string $attribute): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
            return strtolower(trim($value)) === 'true';
        }

        throw ScimException::invalidValue("\"{$attribute}\" must be true or false.");
    }

    /**
     * A non-empty string of at most 255 characters, or null when the value is
     * null or empty (which clears an optional attribute).
     *
     * @throws ScimException
     */
    public static function string(mixed $value, string $attribute): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ScimException::invalidValue("\"{$attribute}\" must be a string.");
        }

        $value = trim($value);

        if (mb_strlen($value) > 255) {
            throw ScimException::invalidValue("\"{$attribute}\" is longer than 255 characters.");
        }

        return $value === '' ? null : $value;
    }

    /**
     * The e-mail address out of an `emails` list: the primary one, else the
     * first of type work, else the first.
     *
     * @throws ScimException
     */
    public static function emailFromList(mixed $emails): ?string
    {
        if (! is_array($emails)) {
            throw ScimException::invalidValue('"emails" must be a list.');
        }

        $items = array_values(array_filter($emails, 'is_array'));

        if ($items === []) {
            return null;
        }

        $pick = collect($items)->first(fn (array $item) => ($item['primary'] ?? false) === true || (is_string($item['primary'] ?? null) && strtolower($item['primary']) === 'true'))
            ?? collect($items)->first(fn (array $item) => is_string($item['type'] ?? null) && strtolower($item['type']) === 'work')
            ?? $items[0];

        return self::email($pick['value'] ?? null);
    }

    /** @throws ScimException */
    public static function email(mixed $value): ?string
    {
        $email = self::string($value, 'emails.value');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ScimException::invalidValue('"'.$email.'" is not a valid e-mail address.');
        }

        return $email;
    }
}
