<?php

namespace App\Services\Execution;

use App\Enums\DbDriver;
use App\Support\ExecutionTimeout;
use Illuminate\Database\Connection as DatabaseConnection;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Database-side session hardening for a query run against a target database.
 *
 * Two jobs, both done by the database itself so they hold even when the
 * application-side guard misses something:
 *
 *  - Read-only: a read request runs inside a READ ONLY transaction
 *    (pgsql, mysql, mariadb) or a `query_only` session (sqlite). SQL Server
 *    has no engine-level read-only mode (`ApplicationIntent=ReadOnly` only
 *    routes to a replica and protects nothing), so there the protection is
 *    the connection's separate read user.
 *  - Timeouts: statement and lock timeouts are derived from
 *    QUERYPROXY_EXECUTION_TIMEOUT and QUERYPROXY_LOCK_TIMEOUT, so the
 *    database stops a runaway statement before the queue job is killed.
 *
 * The READ ONLY transaction is always opened through Laravel's
 * beginTransaction(): a connection inside a transaction is never silently
 * re-established and re-run (a fresh session would be writable).
 */
class TargetSession
{
    /** Seconds the database stops before the job's own execution timeout. */
    public const STATEMENT_TIMEOUT_MARGIN = 5;

    /** PDO_SQLSRV's query timeout attribute (PDO::SQLSRV_ATTR_QUERY_TIMEOUT). */
    private const SQLSRV_ATTR_QUERY_TIMEOUT = 1001;

    /** Error text fragments (lower case) that mean "write attempted in a read-only session". */
    private const LIMIT_ATTEMPTS = 3;

    private const READ_ONLY_VIOLATIONS = [
        'sqlstate[25006]',
        'read-only transaction',
        'read only transaction',
        'read-only option',
        'readonly database',
        'read-only database',
    ];

    public function executionTimeoutSeconds(): int
    {
        return ExecutionTimeout::resolve((string) config('queryproxy.execution_timeout'));
    }

    /** Database-side statement timeout: the execution timeout minus a margin. */
    public function statementTimeoutSeconds(): int
    {
        return max(1, $this->executionTimeoutSeconds() - self::STATEMENT_TIMEOUT_MARGIN);
    }

    public function lockTimeoutSeconds(): int
    {
        return ExecutionTimeout::resolveLock((string) config('queryproxy.lock_timeout'));
    }

    /**
     * PDO options to merge into the target connection's config.
     *
     * @return array<int, int>
     */
    public function pdoOptions(DbDriver $driver): array
    {
        return match ($driver) {
            // sqlsrv has no statement_timeout setting; the driver enforces it.
            DbDriver::Sqlsrv => [self::sqlsrvQueryTimeoutAttribute() => $this->statementTimeoutSeconds()],
            // For sqlite PDO::ATTR_TIMEOUT is the busy timeout (lock wait).
            DbDriver::Sqlite => [PDO::ATTR_TIMEOUT => $this->lockTimeoutSeconds()],
            default => [],
        };
    }

    /**
     * Statements that set the session's statement / lock timeouts.
     *
     * With $transactional the pgsql settings are SET LOCAL (scoped to the
     * open transaction, gone at commit). Outside a transaction SET LOCAL does
     * nothing, so a lone pgsql statement gets a session-level SET instead;
     * it cannot be wrapped in a transaction because VACUUM and
     * CREATE INDEX CONCURRENTLY refuse to run inside one. The connection is
     * per request and purged afterwards. Behind a transaction-pooling proxy
     * (pgbouncer) a session-level SET may not stay on the same backend.
     *
     * @return list<string>
     */
    public function limitStatements(DbDriver $driver, bool $transactional): array
    {
        $statementMs = $this->statementTimeoutSeconds() * 1000;
        $lockMs = $this->lockTimeoutSeconds() * 1000;
        $lockSeconds = $this->lockTimeoutSeconds();
        $statementSeconds = $this->statementTimeoutSeconds();

        return match ($driver) {
            DbDriver::Pgsql => $transactional
                ? [
                    "SET LOCAL statement_timeout = {$statementMs}",
                    "SET LOCAL lock_timeout = {$lockMs}",
                    'SET LOCAL idle_in_transaction_session_timeout = '.($this->executionTimeoutSeconds() * 1000),
                ]
                : [
                    "SET statement_timeout = {$statementMs}",
                    "SET lock_timeout = {$lockMs}",
                ],
            // MAX_EXECUTION_TIME is in milliseconds and applies to SELECT only.
            DbDriver::Mysql => [
                "SET SESSION MAX_EXECUTION_TIME = {$statementMs}",
                "SET SESSION innodb_lock_wait_timeout = {$lockSeconds}",
            ],
            // max_statement_time is in seconds and applies to every statement.
            DbDriver::Mariadb => [
                "SET SESSION max_statement_time = {$statementSeconds}",
                "SET SESSION innodb_lock_wait_timeout = {$lockSeconds}",
            ],
            DbDriver::Sqlsrv => ["SET LOCK_TIMEOUT {$lockMs}"],
            DbDriver::Sqlite => [],
        };
    }

    public function applyLimits(DatabaseConnection $db, DbDriver $driver, bool $transactional): void
    {
        $statements = $this->limitStatements($driver, $transactional);

        // Outside a transaction Laravel re-runs only the statement that hit a
        // lost connection, on the new session; the ones before it stay on the
        // old one. Whenever the PDO changed while the limits were being set,
        // set all of them again so they end up on the session that is used.
        for ($attempt = 0; $attempt < self::LIMIT_ATTEMPTS && $statements !== []; $attempt++) {
            $pdo = $db->getPdo();

            foreach ($statements as $statement) {
                $db->unprepared($statement);
            }

            if ($db->getPdo() === $pdo) {
                return;
            }
        }

        if ($statements !== []) {
            throw new RuntimeException('The database connection kept dropping while the session timeouts were being set.');
        }
    }

    /**
     * Put the session into read-only mode and, where the engine has one, open
     * the READ ONLY transaction the read runs in. Pair with endReadOnly().
     */
    public function beginReadOnly(DatabaseConnection $db, DbDriver $driver): void
    {
        switch ($driver) {
            case DbDriver::Pgsql:
                $db->beginTransaction();
                $db->unprepared('SET TRANSACTION READ ONLY');
                $this->applyLimits($db, $driver, true);

                break;

            case DbDriver::Mysql:
            case DbDriver::Mariadb:
                $this->applyLimits($db, $driver, false);

                // The characteristic applies to the next transaction, which
                // beginTransaction() then starts. If that call had to
                // reconnect, the new session never saw it: fail closed.
                $pdo = $db->getPdo();
                $db->unprepared('SET TRANSACTION READ ONLY');
                $db->beginTransaction();

                if ($db->getPdo() !== $pdo) {
                    $db->rollBack();

                    throw new RuntimeException('The database connection was re-established while opening the read-only transaction; refusing to run the read on a writable session.');
                }

                break;

            case DbDriver::Sqlite:
                $this->applyLimits($db, $driver, false);
                $db->unprepared('PRAGMA query_only = ON');

                break;

            case DbDriver::Sqlsrv:
                // No engine-level read-only mode; the read user is the protection.
                $this->applyLimits($db, $driver, false);

                break;
        }
    }

    public function endReadOnly(DatabaseConnection $db, bool $commit): void
    {
        if ($db->transactionLevel() < 1) {
            return;
        }

        if ($commit) {
            $db->commit();

            return;
        }

        try {
            $db->rollBack();
        } catch (Throwable) {
            // The original error is what matters; a dead connection rolls back on its own.
        }
    }

    /**
     * Whether $error (or anything it wraps) is the database refusing a write
     * inside a read-only session.
     */
    public static function isReadOnlyViolation(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            foreach (self::READ_ONLY_VIOLATIONS as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function sqlsrvQueryTimeoutAttribute(): int
    {
        return defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')
            ? (int) constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')
            : self::SQLSRV_ATTR_QUERY_TIMEOUT;
    }
}
