<?php

namespace App\Services\Masking;

use App\Enums\MaskMatchType;
use App\Models\Connection;
use App\Services\Sql\FilterColumnExtractor;

/**
 * Finds the masked columns a query uses in a filter (WHERE, JOIN ON, HAVING,
 * GROUP BY, ORDER BY). Masking only hides values in the result; a filter such
 * as `WHERE email = 'a@b.c'` still lets the requester probe the real value,
 * so the approver is shown a badge (ACTION_PLAN 030-09). Only a warning: it
 * never blocks a request.
 *
 * Only column-pattern rules are checked; regex and validator rules act on
 * values, which a SQL text does not carry.
 */
class SensitiveFilterDetector
{
    /** Names kept per request; a runaway query cannot bloat the row. */
    private const MAX_COLUMNS = 20;

    private const MAX_NAME_LENGTH = 64;

    public function __construct(
        private readonly FilterColumnExtractor $extractor,
        private readonly Masker $masker,
    ) {}

    /**
     * @return list<string> lower-cased column names, in order of first use
     */
    public function detect(string $sql, Connection $connection): array
    {
        $patterns = $this->masker->rulesFor($connection)
            ->filter(fn ($rule) => $rule->match_type === MaskMatchType::Column)
            ->pluck('pattern');

        if ($patterns->isEmpty()) {
            return [];
        }

        $hits = [];

        foreach ($this->extractor->extract($sql, $connection->driver) as $column) {
            if ($patterns->contains(fn (string $pattern) => $this->masker->columnMatches($pattern, $column))) {
                $hits[] = mb_substr($column, 0, self::MAX_NAME_LENGTH);
            }

            if (count($hits) >= self::MAX_COLUMNS) {
                break;
            }
        }

        return $hits;
    }
}
