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

    /**
     * Writes a stored result (NDJSON, one JSON array per row) to $out as CSV
     * line by line, so the whole result is never held in memory. Column names
     * and cell values are both sanitized against formula injection: the names
     * are as attacker-controlled as the data (`SELECT id AS "=HYPERLINK(...)"`),
     * and on the `SELECT *` path an approving DBA never sees them at review time.
     * The web download and the REST API both come through here.
     *
     * @param  resource  $in
     * @param  resource  $out
     * @param  array<int, mixed>  $columns
     */
    public static function writeFromNdjson($in, $out, array $columns): void
    {
        fputcsv($out, array_map(fn ($column) => self::sanitize($column), $columns));

        while (($line = fgets($in)) !== false) {
            $row = json_decode($line, true);

            if (is_array($row)) {
                fputcsv($out, array_map(
                    fn ($value) => self::sanitize(is_scalar($value) || $value === null ? $value : json_encode($value)),
                    $row,
                ));
            }
        }
    }
}
