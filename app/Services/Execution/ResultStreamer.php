<?php

namespace App\Services\Execution;

use App\Enums\DbDriver;
use Generator;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a query's rows one at a time without ever holding the whole result
 * in PHP memory (ADR-003, unbuffered fetch).
 *
 *  - mysql / mariadb: TargetSession::beginReadOnly() has already switched the
 *    connection to unbuffered mode, so PDO hands rows over as they arrive.
 *  - pgsql: libpq always pulls the full result of a plain query into client
 *    memory, so a SELECT is wrapped in a server-side cursor and read in
 *    batches (FETCH FORWARD) inside the read-only transaction.
 *  - sqlite: PDO steps through the result lazily by nature.
 *  - sqlsrv: pdo_sqlsrv's default forward-only cursor streams rows lazily.
 *
 * Statements PostgreSQL cannot declare as a cursor (SHOW, EXPLAIN, ...) are
 * read with a plain query, which libpq buffers whole on the client. They
 * return a handful of rows, so that is accepted; a statement that is
 * declarable but refused by the server is logged as a warning.
 *
 * The generator must be released (loop finished, or unset()) before the
 * read-only transaction ends: an unbuffered MySQL result has to be freed
 * before COMMIT, and a pgsql cursor is closed here.
 */
class ResultStreamer
{
    /** Rows per FETCH round trip on pgsql. */
    public const FETCH_BATCH = 500;

    private const CURSOR = 'qp_cursor';

    private const SAVEPOINT = 'qp_declare';

    /**
     * @param  int  $rowLimit  the caller stops after this many rows; the
     *                         pgsql cursor never asks the server for more than
     *                         one row past it (enough to detect truncation)
     * @param  array<string, mixed>  $logContext  added to the warning logged when a cursor cannot be used
     * @return Generator<int, object>
     */
    public function rows(DatabaseConnection $db, DbDriver $driver, string $sql, int $rowLimit, array $logContext = []): Generator
    {
        if ($driver === DbDriver::Pgsql && self::isDeclarable($sql) && $this->declare($db, $sql, $logContext)) {
            yield from $this->fetchBatches($db, max(1, $rowLimit + 1));

            return;
        }

        yield from $db->cursor($sql);
    }

    /**
     * Whether the statement is one PostgreSQL accepts in DECLARE ... CURSOR.
     * Whitespace and comments (-- line and nested block comments, which
     * pasted queries often start with) are skipped before the first keyword,
     * and again after each opening parenthesis. Anything else (SHOW, EXPLAIN, ...) is read with a
     * plain query; a wrong "yes" is caught when DECLARE fails.
     */
    public static function isDeclarable(string $sql): bool
    {
        $offset = self::skipLeadingTrivia($sql, 0);

        // Opening parentheses may be followed by more whitespace or comments: ( /* x */ SELECT 1 ).
        while (($sql[$offset] ?? '') === '(') {
            $offset = self::skipLeadingTrivia($sql, $offset + 1);
        }

        return preg_match('/\G(select|with|values|table)\b/i', $sql, $unused, 0, $offset) === 1;
    }

    /**
     * Offset of the first character at or after $from that is not whitespace or a comment.
     * An unterminated block comment consumes the rest of the string.
     */
    private static function skipLeadingTrivia(string $sql, int $from): int
    {
        $length = strlen($sql);
        $i = $from;

        while ($i < $length) {
            if (ctype_space($sql[$i])) {
                $i++;
            } elseif (substr($sql, $i, 2) === '--') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
            } elseif (substr($sql, $i, 2) === '/*') {
                $depth = 1;
                $i += 2;

                while ($i < $length && $depth > 0) {
                    if (substr($sql, $i, 2) === '/*') {
                        $depth++;
                        $i += 2;
                    } elseif (substr($sql, $i, 2) === '*/') {
                        $depth--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
            } else {
                break;
            }
        }

        return $i;
    }

    /**
     * Open the cursor. Returns false (and leaves the transaction healthy)
     * when the server refuses to declare it, so the caller falls back to a
     * plain query and the server reports the real problem, if any. The
     * fallback is logged: it reads the whole result into client memory.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function declare(DatabaseConnection $db, string $sql, array $logContext): bool
    {
        $declare = sprintf('DECLARE %s NO SCROLL CURSOR FOR %s', self::CURSOR, rtrim(trim($sql), ';'));

        $db->unprepared('SAVEPOINT '.self::SAVEPOINT);

        try {
            // Prepared on purpose: the extended protocol accepts one statement
            // only, so the single-statement guarantee holds for the cursor too.
            $db->statement($declare);
        } catch (Throwable $e) {
            $db->unprepared('ROLLBACK TO SAVEPOINT '.self::SAVEPOINT);
            $db->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

            // Class and SQLSTATE only: the message can quote the SQL.
            $previous = $e instanceof QueryException ? $e->getPrevious() : $e;

            Log::warning('Server-side cursor refused; the read falls back to a buffered query.', $logContext + [
                'exception' => $e::class,
                'sqlstate' => (string) ($previous?->getCode() ?? ''),
            ]);

            return false;
        }

        $db->unprepared('RELEASE SAVEPOINT '.self::SAVEPOINT);

        return true;
    }

    /**
     * @return Generator<int, object>
     */
    private function fetchBatches(DatabaseConnection $db, int $remaining): Generator
    {
        try {
            while ($remaining > 0) {
                $size = min(self::FETCH_BATCH, $remaining);
                $batch = $db->select(sprintf('FETCH FORWARD %d FROM %s', $size, self::CURSOR));

                foreach ($batch as $row) {
                    yield $row;
                }

                $remaining -= count($batch);

                if (count($batch) < $size) {
                    break;
                }
            }
        } finally {
            $this->close($db);
        }
    }

    private function close(DatabaseConnection $db): void
    {
        if ($db->transactionLevel() < 1) {
            return;
        }

        try {
            $db->unprepared('CLOSE '.self::CURSOR);
        } catch (Throwable) {
            // The transaction is aborted or gone; ending it closes the cursor.
        }
    }
}
