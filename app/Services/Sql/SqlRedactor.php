<?php

namespace App\Services\Sql;

use App\Enums\DbDriver;
use UnexpectedValueException;

/**
 * Replaces the literal values in a SQL text with a `?` placeholder so the
 * statement's shape can be shown (chat messages, audit views, auditor pages)
 * without the data it carries. ADR-017 and the ADR-009 amendment of 2026-10-20.
 *
 * It is a hand-written linear scanner, not the phpmyadmin lexer SqlInspector
 * uses: that lexer is MySQL-first (a PG double-quoted identifier lexes as a
 * string, dollar quoting is not understood) and a redactor has to be exact about
 * where a string ends, because everything it misjudges is a leak.
 *
 * Two failure modes, deliberately different (both pinned in SqlRedactorTest):
 *
 * - Cannot parse (unterminated string / comment / dollar quote, invalid UTF-8,
 *   MySQL executable comments, or — with an unknown driver — a string whose end
 *   depends on whether backslash is an escape): `redact()` returns null and the
 *   caller shows no SQL at all. It never falls back to the raw text.
 * - Parses but the literal type is not recognised: that literal stays visible.
 *   The one such case is the money literal (`$12.50`) on a driver other than
 *   SQL Server, where it is indistinguishable from a `$1` positional parameter.
 *
 * Recognised and replaced: single-quoted strings (with `''` doubling; `N`, `E`,
 * `X`, `B`, `U&` and `_charset` prefixes; backslash escapes where the driver has
 * them), double-quoted strings where the driver treats them as strings (MySQL,
 * MariaDB, SQLite, unknown driver), PostgreSQL dollar-quoted strings, hex (`0x1F`)
 * and binary (`0b101`) literals, and integer / decimal / exponent numbers
 * (`1_000` included). Comments are dropped, since they can hold values too.
 * On SQL Server a text that mentions `QUOTED_IDENTIFIER` or `ANSI_DEFAULTS` (the SET
 * switches that make `"..."` a string) has its double-quoted tokens redacted too.
 * Identifiers (`"x"` on PG / SQL Server, `` `x` ``, `[x]`), keywords, NULL/TRUE/
 * FALSE, `@vars`, `:named` and `$1` parameters are kept.
 */
class SqlRedactor
{
    public const PLACEHOLDER = '?';

    /**
     * @param  ?DbDriver  $driver  The connection's driver; null when unknown, in which case the
     *                             scanner takes the conservative reading wherever drivers differ.
     * @return ?string null when the SQL cannot be redacted with confidence.
     */
    public function redact(string $sql, ?DbDriver $driver = null): ?string
    {
        if ($sql === '') {
            return '';
        }

        if (! mb_check_encoding($sql, 'UTF-8')) {
            return null;
        }

        try {
            return $this->scan($sql, $driver);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * SQL Server: after `SET QUOTED_IDENTIFIER OFF` (or `SET ANSI_DEFAULTS OFF`, which
     * implies it) a double-quoted token is a string literal, and the setting applies to
     * the rest of the batch at parse time. SqlInspector allows both switches, so a
     * request can carry them. The text is searched as a whole, comments and strings
     * included: a false positive only over-redacts (every `"..."` becomes `?`), a
     * false negative would leak the value. Hidden by concatenation it cannot be seen,
     * but then it only takes effect inside dynamic SQL, which is itself a redacted
     * string literal here.
     */
    private function switchesQuotedIdentifier(string $sql, ?DbDriver $driver): bool
    {
        return $driver === DbDriver::Sqlsrv
            && preg_match('/QUOTED_IDENTIFIER|ANSI_DEFAULTS/i', $sql) === 1;
    }

    private function scan(string $sql, ?DbDriver $driver): string
    {
        $mysqlish = $driver === DbDriver::Mysql || $driver === DbDriver::Mariadb;
        $doubleQuoteIsIdentifier = ($driver === DbDriver::Pgsql || $driver === DbDriver::Sqlsrv)
            && ! $this->switchesQuotedIdentifier($sql, $driver);
        $n = strlen($sql);
        $i = 0;
        $out = '';

        while ($i < $n) {
            $c = $sql[$i];

            if (strpos(" \t\r\n\f\v", $c) !== false) {
                $len = strspn($sql, " \t\r\n\f\v", $i);
                $out .= substr($sql, $i, $len);
                $i += $len;

                continue;
            }

            if ($c === "'") {
                $i = $this->quotedEnd($sql, $i, "'", $driver, false);
                $out .= self::PLACEHOLDER;

                continue;
            }

            if ($c === '"') {
                if ($doubleQuoteIsIdentifier) {
                    $end = $this->quotedEnd($sql, $i, '"', $driver, false, identifier: true);
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;
                } else {
                    $i = $this->quotedEnd($sql, $i, '"', $driver, false);
                    $out .= self::PLACEHOLDER;
                }

                continue;
            }

            if ($c === '`') {
                $end = $this->quotedEnd($sql, $i, '`', $driver, false, identifier: true);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '[' && $driver === DbDriver::Sqlsrv) {
                $end = $this->bracketEnd($sql, $i);
                $out .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                // MySQL needs whitespace after `--`; elsewhere `--` always starts a comment.
                $after = $sql[$i + 2] ?? "\n";
                if (! $mysqlish || ord($after) <= 32) {
                    $i += strcspn($sql, "\r\n", $i);

                    continue;
                }
            }

            if ($c === '#' && ($mysqlish || $driver === null)) {
                $i += strcspn($sql, "\r\n", $i);

                continue;
            }

            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                if (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!') {
                    throw new UnexpectedValueException('Executable comment.');
                }
                $i = $this->commentEnd($sql, $i, $driver);
                $out .= ' ';

                continue;
            }

            if ($c === '$') {
                [$i, $out] = $this->dollar($sql, $i, $out, $driver);

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $this->startsFraction($sql, $i, $out))) {
                $i += $this->match(
                    '/\G(?:0[xX][0-9a-fA-F_]+|0[bB][01_]+|(?:\d[\d_]*(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)[A-Za-z0-9_$\x80-\xff]*/',
                    $sql,
                    $i,
                );
                $out .= self::PLACEHOLDER;

                continue;
            }

            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $sql, $m, 0, $i) === 1) {
                $word = $m[0];
                $next = $i + strlen($word);

                if (($sql[$next] ?? '') === "'" && $this->isStringPrefix($word)) {
                    $i = $this->quotedEnd($sql, $next, "'", $driver, strtolower($word) === 'e');
                    $out .= self::PLACEHOLDER;

                    continue;
                }

                if (strtolower($word) === 'u' && substr($sql, $next, 2) === "&'") {
                    $i = $this->quotedEnd($sql, $next + 1, "'", $driver, false);
                    $out .= self::PLACEHOLDER;

                    continue;
                }

                $out .= $word;
                $i = $next;

                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    private function isStringPrefix(string $word): bool
    {
        return in_array(strtolower($word), ['n', 'e', 'x', 'b'], true)
            || preg_match('/^_[A-Za-z0-9]+$/', $word) === 1;
    }

    /** A `.` starts a number (`.5`) only when it does not follow an identifier or a closing quote. */
    private function startsFraction(string $sql, int $i, string $out): bool
    {
        if (! ctype_digit($sql[$i + 1] ?? '')) {
            return false;
        }

        $previous = substr($out, -1);

        return $previous === '' || preg_match('/[A-Za-z0-9_)\]"`\x80-\xff]/', $previous) !== 1;
    }

    /**
     * PostgreSQL dollar quoting, `$1` parameters and money literals.
     *
     * @return array{int, string}
     */
    private function dollar(string $sql, int $i, string $out, ?DbDriver $driver): array
    {
        if (preg_match('/\G\$\d+(?:\.\d+)?/', $sql, $m, 0, $i) === 1) {
            // SQL Server: a money literal. Elsewhere `$12` is a positional parameter and
            // `$12.50` a money literal we cannot tell apart from one; both stay visible.
            return [$i + strlen($m[0]), $out.($driver === DbDriver::Sqlsrv ? self::PLACEHOLDER : $m[0])];
        }

        if (($driver === DbDriver::Pgsql || $driver === null)
            && preg_match('/\G\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $m, 0, $i) === 1) {
            $close = strpos($sql, $m[0], $i + strlen($m[0]));
            if ($close === false) {
                throw new UnexpectedValueException('Unterminated dollar quote.');
            }

            return [$close + strlen($m[0]), $out.self::PLACEHOLDER];
        }

        return [$i + 1, $out.'$'];
    }

    /**
     * Index just past the closing quote of the quoted run that opens at $start.
     *
     * @param  bool  $escapePrefix  PostgreSQL `E'..'`: backslash escapes even though plain strings have none.
     * @param  bool  $identifier  Backslash is never an escape in an identifier.
     */
    private function quotedEnd(string $sql, int $start, string $quote, ?DbDriver $driver, bool $escapePrefix, bool $identifier = false): int
    {
        $backslash = $identifier ? false : match ($driver) {
            DbDriver::Mysql, DbDriver::Mariadb => true,
            DbDriver::Pgsql => $escapePrefix,
            DbDriver::Sqlite, DbDriver::Sqlsrv => false,
            null => $escapePrefix ?: null,
        };

        if ($backslash === null) {
            // Unknown driver: where the string ends depends on whether `\` escapes.
            $plain = $this->scanQuoted($sql, $start, $quote, false);
            $escaped = $this->scanQuoted($sql, $start, $quote, true);
            if ($plain !== $escaped) {
                throw new UnexpectedValueException('Ambiguous string end.');
            }
            $end = $plain;
        } else {
            $end = $this->scanQuoted($sql, $start, $quote, $backslash);
        }

        return $end ?? throw new UnexpectedValueException('Unterminated quoted run.');
    }

    private function scanQuoted(string $sql, int $start, string $quote, bool $backslash): ?int
    {
        $n = strlen($sql);
        $stops = $backslash ? $quote.'\\' : $quote;
        $i = $start + 1;

        while ($i < $n) {
            $i += strcspn($sql, $stops, $i);
            if ($i >= $n) {
                return null;
            }

            if ($sql[$i] === '\\') {
                $i += 2;

                continue;
            }

            if (($sql[$i + 1] ?? '') === $quote) {
                $i += 2;

                continue;
            }

            return $i + 1;
        }

        return null;
    }

    private function bracketEnd(string $sql, int $start): int
    {
        $n = strlen($sql);
        $i = $start + 1;

        while ($i < $n) {
            $i += strcspn($sql, ']', $i);
            if ($i >= $n) {
                break;
            }
            if (($sql[$i + 1] ?? '') === ']') {
                $i += 2;

                continue;
            }

            return $i + 1;
        }

        throw new UnexpectedValueException('Unterminated bracket identifier.');
    }

    /** PostgreSQL nests block comments; the other drivers do not. With an unknown driver both readings must agree. */
    private function commentEnd(string $sql, int $start, ?DbDriver $driver): int
    {
        $end = match ($driver) {
            DbDriver::Pgsql => $this->scanComment($sql, $start, true),
            null => $this->scanComment($sql, $start, true) === ($flat = $this->scanComment($sql, $start, false)) ? $flat : null,
            default => $this->scanComment($sql, $start, false),
        };

        return $end ?? throw new UnexpectedValueException('Unterminated or ambiguous comment.');
    }

    private function scanComment(string $sql, int $start, bool $nested): ?int
    {
        $n = strlen($sql);
        $depth = 1;
        $i = $start + 2;

        while ($i < $n - 1) {
            $pair = $sql[$i].$sql[$i + 1];
            if ($pair === '*/') {
                $depth--;
                $i += 2;
                if ($depth === 0) {
                    return $i;
                }

                continue;
            }
            if ($nested && $pair === '/*') {
                $depth++;
                $i += 2;

                continue;
            }
            $i++;
        }

        return null;
    }

    private function match(string $pattern, string $sql, int $offset): int
    {
        if (preg_match($pattern, $sql, $m, 0, $offset) !== 1) {
            throw new UnexpectedValueException('Scanner could not advance.');
        }

        return strlen($m[0]);
    }
}
