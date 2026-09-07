<?php

namespace App\Support;

/**
 * CSV formula-injection guard: spreadsheet apps execute cells starting with
 * = + - @ (or a tab/CR), so exported values get a leading apostrophe, which
 * Excel/Sheets treat as "literal text" and hide.
 */
class Csv
{
    public static function sanitize(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'".$value;
        }

        return $value;
    }
}
