<?php

namespace App\Services\Sql;

use App\Enums\DbDriver;

/**
 * Lists the column names a SQL text uses where they narrow, join, group or
 * order the rows: WHERE, JOIN ... ON / USING, HAVING, GROUP BY and ORDER BY.
 * Columns that only appear in the select list (or in INSERT / SET targets)
 * are not reported. Feeds the "sensitive column in filter" badge (ACTION_PLAN
 * 030-09, ADR-014): a masked column can still be probed with
 * `WHERE email = '…'`.
 *
 * It is a hand-written linear scanner, like SqlRedactor, and for the same
 * reason: the phpmyadmin lexer is MySQL-first and misreads PostgreSQL quoting.
 * It is not a guard. SqlInspector decides what may run; this only produces a
 * hint for the approver, so it errs towards reporting more rather than less
 * (an unknown driver is scanned under both backslash readings and the
 * results are merged).
 *
 * Known limits, accepted for the basic version: column aliases are not
 * resolved (`SELECT email AS e … HAVING e = 'x'`), derived tables and CTEs
 * are not traced back to base columns (lineage is v0.5.0), and a text that
 * cannot be scanned to its end (unterminated string or comment) reports what
 * was found before the break.
 *
 * Only the last part of a qualified name is returned (`u.email` → `email`),
 * lower-cased, without quoting, each name once in order of first use.
 */
class FilterColumnExtractor
{
    /** Words that switch the current query level into "filter" mode. */
    private const FILTER_KEYWORDS = ['where', 'having', 'on', 'using'];

    /** Words that end filter mode on their level (the clause that follows is not a filter). */
    private const CLAUSE_END_KEYWORDS = [
        'select', 'from', 'join', 'inner', 'left', 'right', 'full', 'outer', 'cross', 'natural',
        'union', 'intersect', 'except', 'minus', 'limit', 'offset', 'fetch', 'for', 'set',
        'values', 'into', 'returning', 'window', 'with', 'lateral',
    ];

    /** Words that follow `ON` without starting a join condition. */
    private const NON_JOIN_ON_FOLLOWERS = ['conflict', 'duplicate', 'delete', 'update', 'commit'];

    /** Operators, literals and clause words that can appear in filter mode and are not columns. */
    private const NOISE_WORDS = [
        'and', 'or', 'not', 'in', 'is', 'null', 'true', 'false', 'like', 'ilike', 'rlike', 'regexp',
        'similar', 'to', 'between', 'exists', 'any', 'all', 'some', 'case', 'when', 'then', 'else',
        'end', 'asc', 'desc', 'nulls', 'first', 'last', 'distinct', 'by', 'escape', 'collate',
        'interval', 'over', 'partition', 'unknown', 'div', 'mod', 'xor', 'binary', 'default',
        'current_date', 'current_time', 'current_timestamp', 'localtime', 'localtimestamp',
        'current_user', 'session_user', 'user', 'only', 'row', 'rows', 'range', 'preceding',
        'following', 'unbounded', 'current', 'filter', 'within', 'group', 'order', 'as',
        'both', 'leading', 'trailing', 'placing',
    ];

    /**
     * @return list<string>
     */
    public function extract(string $sql, ?DbDriver $driver = null): array
    {
        if ($sql === '') {
            return [];
        }

        // Backslash is an escape inside a string on MySQL/MariaDB only; for an unknown
        // driver the string end is ambiguous, so scan both ways and merge.
        $readings = match ($driver) {
            DbDriver::Mysql, DbDriver::Mariadb => [true],
            null => [true, false],
            default => [false],
        };

        $found = [];

        foreach ($readings as $backslashEscapes) {
            foreach ($this->scan($sql, $driver, $backslashEscapes) as $name) {
                $found[$name] = true;
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * @return list<string>
     */
    private function scan(string $sql, ?DbDriver $driver, bool $backslashEscapes): array
    {
        $mysqlish = $driver === DbDriver::Mysql || $driver === DbDriver::Mariadb || $driver === null;
        $doubleQuoteIsString = ($driver === DbDriver::Mysql || $driver === DbDriver::Mariadb)
            && preg_match('/ANSI_QUOTES|sql_mode/i', $sql) !== 1;
        $bracketIdentifiers = $driver === DbDriver::Sqlsrv || $driver === null;
        $dollarQuoting = $driver === DbDriver::Pgsql || $driver === null;

        $n = strlen($sql);
        $i = 0;
        $columns = [];
        $levels = [$this->newLevel('none', true)];
        $skipNext = false;
        // True right after a function name, so the next `(` opens a call's argument list.
        $fnParen = false;
        $inExecutableComment = false;

        while ($i < $n) {
            $c = $sql[$i];

            if (strpos(" \t\r\n\f\v", $c) !== false) {
                $i += strspn($sql, " \t\r\n\f\v", $i);

                continue;
            }

            // Comments. `-- ` always, `--x` only outside MySQL; `#` on MySQL.
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && (! $mysqlish || ord($sql[$i + 2] ?? "\n") <= 32)) {
                $i = $this->lineEnd($sql, $i);

                continue;
            }

            if ($c === '#' && $mysqlish && $driver !== null) {
                $i = $this->lineEnd($sql, $i);

                continue;
            }

            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                // MySQL executable comment: the body is code, so scan it instead of skipping it.
                if ($mysqlish && preg_match('/\G\/\*!(?:M!)?\d*/A', $sql, $m, 0, $i) === 1) {
                    $inExecutableComment = true;
                    $i += strlen($m[0]);

                    continue;
                }

                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    break;
                }

                $i = $end + 2;

                continue;
            }

            if ($c === '*' && ($sql[$i + 1] ?? '') === '/' && $inExecutableComment) {
                $inExecutableComment = false;
                $i += 2;

                continue;
            }

            // Only a name directly after `AS`, `::`, `:param` or `@var` is skipped, and only a `(`
            // directly after a function name opens an argument list; any other token ends both.
            if (! $this->startsPart($sql, $i, $doubleQuoteIsString, $bracketIdentifiers)) {
                if ($c !== ':' && $c !== '@') {
                    $skipNext = false;
                }

                if ($c !== '(') {
                    $fnParen = false;
                }
            }

            if ($c === "'") {
                $i = $this->stringEnd($sql, $i, "'", $backslashEscapes || $this->hasEscapePrefix($sql, $i, $driver));

                if ($i === null) {
                    break;
                }

                continue;
            }

            if ($c === '"' && $doubleQuoteIsString) {
                $i = $this->stringEnd($sql, $i, '"', $backslashEscapes);

                if ($i === null) {
                    break;
                }

                continue;
            }

            if ($c === '$') {
                if ($dollarQuoting && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $m, 0, $i) === 1) {
                    $close = strpos($sql, $m[0], $i + strlen($m[0]));

                    if ($close === false) {
                        break;
                    }

                    $i = $close + strlen($m[0]);

                    continue;
                }

                // `$1` positional parameter, or a money literal.
                $i++;
                $i += strspn($sql, '0123456789.', $i);

                continue;
            }

            if ($c === '(') {
                $parent = $levels[count($levels) - 1];
                $levels[] = $this->newLevel($parent['ctx'], false, $fnParen);
                $fnParen = false;
                $i++;

                continue;
            }

            if ($c === ')') {
                if (count($levels) > 1) {
                    array_pop($levels);
                }

                $i++;

                continue;
            }

            if ($c === ';') {
                $levels = [$this->newLevel('none', true)];
                $skipNext = false;
                $fnParen = false;
                $i++;

                continue;
            }

            if ($c === ':') {
                // `x::type` cast or `:named` parameter: a name that follows is not a column.
                // A bare `:` (array slice `a[1:2]`, `:=`) skips nothing.
                $isCast = ($sql[$i + 1] ?? '') === ':';
                $i += $isCast ? 2 : 1;
                $j = $isCast ? $i + strspn($sql, " \t\r\n", $i) : $i;
                $skipNext = $j < $n && ($sql[$j] === '_' || ctype_alpha($sql[$j]) || $sql[$j] === '"');

                continue;
            }

            if ($c === '@') {
                // `@var` / `@@sysvar`; operators such as `@>`, `<@`, `@?` skip nothing.
                $i += strspn($sql, '@', $i);
                $skipNext = $i < $n && ($sql[$i] === '_' || ctype_alpha($sql[$i]) || $sql[$i] === '`' || $sql[$i] === "'" || $sql[$i] === '"');

                continue;
            }

            if (ctype_digit($c)) {
                $i += strspn($sql, '0123456789abcdefABCDEFxX_.', $i);

                continue;
            }

            if ($this->startsPart($sql, $i, $doubleQuoteIsString, $bracketIdentifiers)) {
                $chain = $this->readChain($sql, $i, $doubleQuoteIsString, $bracketIdentifiers);

                if ($chain === null) {
                    break;
                }

                [$parts, $i] = $chain;
                $last = $parts[count($parts) - 1];
                $isFunctionCall = $this->nextSignificant($sql, $i) === '(';

                // `E'…'`, `N'…'`, `_utf8'…'`: a string prefix, not a name.
                if (count($parts) === 1 && ! $last['quoted'] && ($sql[$i] ?? '') === "'"
                    && (strlen($last['name']) === 1 || $last['name'][0] === '_')) {
                    continue;
                }

                $lower = strtolower($last['name']);
                $top = count($levels) - 1;

                if ($skipNext) {
                    $skipNext = false;

                    continue;
                }

                $fnParen = false;

                if (count($parts) === 1 && ! $last['quoted'] && ! ($isFunctionCall && ($lower === 'left' || $lower === 'right'))) {
                    if ($lower === 'as') {
                        $skipNext = true;

                        continue;
                    }

                    if (in_array($lower, self::FILTER_KEYWORDS, true)) {
                        $levels[$top]['ctx'] = $lower === 'on' && in_array($this->peekWord($sql, $i), self::NON_JOIN_ON_FOLLOWERS, true)
                            ? 'none'
                            : 'filter';

                        continue;
                    }

                    if (in_array($lower, self::CLAUSE_END_KEYWORDS, true)) {
                        // `trim(both ' ' from col)`, `substring(s from 1 for 3)`: not clauses.
                        if ($levels[$top]['fn'] && ($lower === 'from' || $lower === 'for')) {
                            continue;
                        }

                        $levels[$top]['ctx'] = 'none';
                        $levels[$top]['query'] = $levels[$top]['query'] || $lower === 'select';
                        $levels[$top]['fn'] = $levels[$top]['fn'] && $lower !== 'select';

                        continue;
                    }

                    if (($lower === 'group' || $lower === 'order') && $this->peekWord($sql, $i) === 'by') {
                        if ($levels[$top]['query']) {
                            $levels[$top]['ctx'] = 'filter';
                        }

                        continue;
                    }

                    if (in_array($lower, self::NOISE_WORDS, true)) {
                        continue;
                    }
                }

                $fnParen = $isFunctionCall;

                if ($isFunctionCall || $last['name'] === '*' || $levels[count($levels) - 1]['ctx'] !== 'filter') {
                    continue;
                }

                $columns[strtolower($last['name'])] = true;

                continue;
            }

            // Operators and other punctuation.
            $i++;
        }

        return array_map('strval', array_keys($columns));
    }

    /**
     * @return array{ctx: string, query: bool, fn: bool}
     */
    private function newLevel(string $ctx, bool $query, bool $fn = false): array
    {
        return ['ctx' => $ctx, 'query' => $query, 'fn' => $fn];
    }

    private function lineEnd(string $sql, int $i): int
    {
        $end = strpos($sql, "\n", $i);

        return $end === false ? strlen($sql) : $end + 1;
    }

    /**
     * Index after the closing quote of the string starting at $i, or null when it never closes.
     */
    private function stringEnd(string $sql, int $i, string $quote, bool $backslashEscapes): ?int
    {
        $n = strlen($sql);
        $i++;

        while ($i < $n) {
            $c = $sql[$i];

            if ($backslashEscapes && $c === '\\') {
                $i += 2;

                continue;
            }

            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return null;
    }

    /** PostgreSQL `E'…'` strings take backslash escapes whatever the default. */
    private function hasEscapePrefix(string $sql, int $quotePos, ?DbDriver $driver): bool
    {
        if ($driver !== DbDriver::Pgsql || $quotePos === 0 || strtolower($sql[$quotePos - 1]) !== 'e') {
            return false;
        }

        return $quotePos === 1 || ! $this->isWordChar($sql[$quotePos - 2]);
    }

    private function isWordChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 128;
    }

    private function startsPart(string $sql, int $i, bool $doubleQuoteIsString, bool $bracketIdentifiers): bool
    {
        $c = $sql[$i];

        return ctype_alpha($c) || $c === '_' || ord($c) >= 128 || $c === '`'
            || ($c === '"' && ! $doubleQuoteIsString)
            || ($c === '[' && $bracketIdentifiers);
    }

    /**
     * Reads `a`, `a.b`, `"a"."b"`, `[a].[b]`, `a.*` starting at $i.
     *
     * @return ?array{list<array{name: string, quoted: bool}>, int} null when a quoted part never closes
     */
    private function readChain(string $sql, int $i, bool $doubleQuoteIsString, bool $bracketIdentifiers): ?array
    {
        $parts = [];

        while (true) {
            $c = $sql[$i] ?? '';

            if ($c === '`' || ($c === '"' && ! $doubleQuoteIsString)) {
                $end = $this->stringEnd($sql, $i, $c, false);

                if ($end === null) {
                    return null;
                }

                $parts[] = ['name' => str_replace($c.$c, $c, substr($sql, $i + 1, $end - $i - 2)), 'quoted' => true];
                $i = $end;
            } elseif ($c === '[' && $bracketIdentifiers) {
                $end = strpos($sql, ']', $i + 1);

                while ($end !== false && ($sql[$end + 1] ?? '') === ']') {
                    $end = strpos($sql, ']', $end + 2);
                }

                if ($end === false) {
                    return null;
                }

                $parts[] = ['name' => str_replace(']]', ']', substr($sql, $i + 1, $end - $i - 1)), 'quoted' => true];
                $i = $end + 1;
            } elseif ($c === '*') {
                $parts[] = ['name' => '*', 'quoted' => false];
                $i++;

                break;
            } else {
                $start = $i;

                while ($i < strlen($sql) && $this->isWordChar($sql[$i])) {
                    $i++;
                }

                if ($i === $start) {
                    break;
                }

                $parts[] = ['name' => substr($sql, $start, $i - $start), 'quoted' => false];
            }

            // A dot (spaces allowed around it) continues the chain.
            $j = $i + strspn($sql, " \t\r\n", $i);

            if (($sql[$j] ?? '') !== '.') {
                break;
            }

            $j++;
            $j += strspn($sql, " \t\r\n", $j);

            if (! $this->startsPart($sql, $j, $doubleQuoteIsString, $bracketIdentifiers) && ($sql[$j] ?? '') !== '*') {
                $i = $j;

                break;
            }

            $i = $j;
        }

        if ($parts === []) {
            return [[['name' => '', 'quoted' => false]], $i + 1];
        }

        return [$parts, $i];
    }

    /** The next non-blank character after $i, or ''. */
    private function nextSignificant(string $sql, int $i): string
    {
        $i += strspn($sql, " \t\r\n\f\v", $i);

        return $sql[$i] ?? '';
    }

    /** The lower-cased bare word after $i (comments are not looked through), or ''. */
    private function peekWord(string $sql, int $i): string
    {
        $i += strspn($sql, " \t\r\n\f\v", $i);

        return preg_match('/\G[A-Za-z_]+/A', $sql, $m, 0, $i) === 1 ? strtolower($m[0]) : '';
    }
}
