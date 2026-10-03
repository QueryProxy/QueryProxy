<?php

namespace App\Database;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Support\Str;
use Throwable;

/**
 * Laravel's lost-connection detector, minus the read-only errors.
 *
 * The framework detector counts PostgreSQL's "SQLSTATE[25006]: Read only sql
 * transaction" and MySQL/MariaDB's "--read-only option" errors as a lost
 * connection. Outside a transaction Laravel answers a lost connection by
 * reconnecting and running the statement again, on a fresh session. A session
 * that was made read-only on purpose (SET default_transaction_read_only = on,
 * SET SESSION TRANSACTION READ ONLY, ...) is therefore not a defence at all:
 * the write that the server refused is silently retried on a new, writable
 * session and goes through.
 *
 * A read-only error is a verdict about the statement, not about the
 * connection, so this detector reports it as "not lost" and lets the
 * exception reach the caller. Every other message is left to the framework
 * detector it wraps. The trade-off: a MySQL primary that was demoted to a
 * read-only replica during a failover no longer gets a transparent reconnect;
 * the statement fails instead of being retried.
 */
final class ReadOnlyAwareLostConnectionDetector implements LostConnectionDetector
{
    /**
     * Fragments of the errors a server raises when it refuses a write because
     * the session, transaction or server is read-only. Matched case-insensitively.
     *
     * - SQLSTATE 25006 is "read only sql transaction" on PostgreSQL and MySQL's
     *   ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION (1792).
     * - "read-only option" covers MySQL/MariaDB 1290 for both --read-only and
     *   --super-read-only.
     * - The transaction phrases cover drivers that leave the SQLSTATE out of
     *   the message: "cannot execute INSERT in a read-only transaction"
     *   (PostgreSQL), "Cannot execute statement in a READ ONLY transaction"
     *   (MySQL).
     */
    private const READ_ONLY_ERRORS = [
        'SQLSTATE[25006]',
        'read-only option',
        'read-only transaction',
        'read only transaction',
    ];

    public function __construct(private readonly LostConnectionDetector $inner) {}

    public function causedByLostConnection(Throwable $e): bool
    {
        if (self::isReadOnlyError($e)) {
            return false;
        }

        return $this->inner->causedByLostConnection($e);
    }

    private static function isReadOnlyError(Throwable $e): bool
    {
        return Str::contains($e->getMessage(), self::READ_ONLY_ERRORS, ignoreCase: true);
    }
}
