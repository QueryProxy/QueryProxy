<?php

namespace App\Services\Execution;

use App\Enums\DbDriver;
use App\Models\Connection;
use App\Services\Connections\DynamicConnectionFactory;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Stops a statement that is running on a target database.
 *
 * Two halves: identify() reads the id (and a start stamp) of the session a
 * run executes on, and cancel() signals that session from a second,
 * short-lived connection (the run's own session is busy and cannot be asked
 * anything).
 *
 *  - pgsql:          pg_cancel_backend(pid)  cancels the statement, the session stays
 *  - pgsql on CockroachDB (detected through version()): it has no
 *                    pg_cancel_backend; CANCEL QUERIES names the statements of
 *                    the session by its session_id (kept in the stamp as
 *                    "crdb:<32 hex>"), and the session stays. YugabyteDB
 *                    speaks the PostgreSQL protocol and functions, so it takes
 *                    the plain pgsql path.
 *  - mysql/mariadb:  KILL QUERY pid          aborts the statement, the session stays
 *  - sqlsrv:         KILL spid               ends the session; its open transaction rolls back
 *  - sqlite:         not supported (in-process, no second session to signal)
 *
 * Servers reuse session ids (SQL Server hands out the lowest free SPID at
 * once), so an id alone can end up naming somebody else's session by the time
 * the signal arrives. pgsql and sqlsrv therefore also record when the session
 * started, and the signal goes out in one statement that checks that stamp
 * first. MySQL / MariaDB connection ids only grow, so they need no stamp. If
 * the stamp cannot be read, SQL Server (where KILL ends a whole session) is not
 * signalled at all; pgsql falls back to the plain cancel, which only interrupts
 * a statement.
 *
 * Signalling only works for sessions of the same database user (or a user
 * with the server's signalling privilege). A run that used the read-only
 * identity is signalled with it first, then with the main identity; any other
 * run only with the main identity.
 * Nothing here throws: a stop that could not be delivered returns false.
 *
 * The blocklist in app/Services/Sql keeps user SQL from calling
 * pg_cancel_backend / KILL; this class is the application's own use of them.
 */
class BackendCanceller
{
    /** What a SQL Server login_time stamp looks like (CONVERT style 121 of a datetime: milliseconds at most); anything else is never put into SQL. */
    private const SQLSRV_STAMP = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,3})?$/';

    /** The stamp identify() records for a CockroachDB session: its session_id, 32 hex digits, behind a marker no PostgreSQL timestamp can have. */
    private const COCKROACH_STAMP = '/^crdb:([0-9a-f]{32})$/';

    public function __construct(private DynamicConnectionFactory $factory) {}

    public function supports(DbDriver $driver): bool
    {
        return $driver !== DbDriver::Sqlite;
    }

    /**
     * The target's id for the session behind $db plus its start stamp, or null
     * when the driver has none or it cannot be read. Read through the raw PDO,
     * outside Laravel's query logging and reconnect logic: it must describe the
     * very session the next statement runs on, and a failure must never fail
     * the run.
     *
     * @return array{pid: int, token: string|null}|null
     */
    public function identify(DatabaseConnection $db, DbDriver $driver): ?array
    {
        $sql = match ($driver) {
            DbDriver::Pgsql => 'SELECT pg_backend_pid(), (SELECT backend_start::text FROM pg_stat_activity WHERE pid = pg_backend_pid()), version()',
            DbDriver::Mysql, DbDriver::Mariadb => 'SELECT CONNECTION_ID()',
            DbDriver::Sqlsrv => 'SELECT @@SPID, (SELECT CONVERT(varchar(30), login_time, 121) FROM sys.dm_exec_sessions WHERE session_id = @@SPID)',
            DbDriver::Sqlite => null,
        };

        if ($sql === null) {
            return null;
        }

        try {
            $statement = $db->getPdo()->query($sql);

            if ($statement === false) {
                return null;
            }

            $row = $statement->fetch(PDO::FETCH_NUM);
            // An unbuffered MySQL connection takes no new statement until this one is closed.
            $statement->closeCursor();

            if (! is_array($row) || ! is_numeric($row[0] ?? null) || (int) $row[0] < 1) {
                return null;
            }

            $token = isset($row[1]) && is_string($row[1]) && $row[1] !== '' ? $row[1] : null;

            if ($driver === DbDriver::Pgsql && is_string($row[2] ?? null) && str_starts_with($row[2], 'CockroachDB')) {
                $token = $this->cockroachStamp($db);
            }

            return ['pid' => (int) $row[0], 'token' => $token];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The stamp of a CockroachDB session: its session_id. pg_backend_pid() is
     * only a number derived from it and names nothing a CANCEL can take, and
     * pg_stat_activity is empty there. Null when it cannot be read; the run is
     * then simply not stoppable by the user.
     */
    private function cockroachStamp(DatabaseConnection $db): ?string
    {
        $statement = $db->getPdo()->query('SHOW session_id');

        if ($statement === false) {
            return null;
        }

        $id = $statement->fetchColumn();
        $statement->closeCursor();

        return is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? 'crdb:'.$id : null;
    }

    /**
     * Ask the target to stop the statement running on session $pid.
     *
     * @param  string|null  $token  the start stamp identify() returned for that session
     * @param  bool  $asReader  the run used the read-only identity, so try it first
     * @return bool true when the target accepted the signal for that very session; false when it could not be delivered
     */
    public function cancel(Connection $connection, int $pid, ?string $token = null, bool $asReader = false): bool
    {
        $driver = $connection->driver;

        if ($pid < 1 || ! $this->supports($driver)) {
            return false;
        }

        $identities = $asReader && $connection->hasReadCredentials() ? [true, false] : [false];

        foreach ($identities as $readIdentity) {
            $name = null;

            try {
                $name = $this->factory->configureControl($connection, $readIdentity);

                if ($this->signal(DB::connection($name), $driver, $pid, $token)) {
                    return true;
                }
            } catch (Throwable $e) {
                // Class and code only: the message can quote host or user names.
                Log::warning('Could not signal the target session to stop a statement.', [
                    'connection_id' => $connection->id,
                    'read_identity' => $readIdentity,
                    'exception' => $e::class,
                    'code' => (string) ($e->getPrevious()?->getCode() ?? $e->getCode()),
                ]);
            } finally {
                if ($name !== null) {
                    DB::purge($name);
                    config(["database.connections.{$name}" => null]);
                }
            }
        }

        return false;
    }

    private function signal(DatabaseConnection $db, DbDriver $driver, int $pid, ?string $token): bool
    {
        // $pid is an int, so interpolating it into the statements that cannot take a parameter is safe.
        switch ($driver) {
            case DbDriver::Pgsql:
                if ($token !== null && preg_match(self::COCKROACH_STAMP, $token, $session) === 1) {
                    return $this->cancelCockroach($db, $session[1]);
                }

                // Another role's row shows no backend_start, so the stamp also
                // keeps the wrong identity from "succeeding" on a foreign session.
                return $token === null
                    ? (bool) $db->scalar('SELECT pg_cancel_backend(?)', [$pid])
                    : (bool) $db->scalar(
                        'SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE pid = ? AND backend_start = CAST(? AS timestamptz)',
                        [$pid, $token],
                    );

            case DbDriver::Mysql:
            case DbDriver::Mariadb:
                return $db->unprepared('KILL QUERY '.$pid);

            case DbDriver::Sqlsrv:
                if ($token === null || preg_match(self::SQLSRV_STAMP, $token) !== 1) {
                    return false;
                }

                // CONVERT with style 121 reads the stamp the same way whatever
                // the login's language or DATEFORMAT is; a bare string literal
                // would be read as day-first (or fail) under, say, British.
                $match = "SELECT 1 FROM sys.dm_exec_sessions WHERE session_id = {$pid} AND login_time = CONVERT(datetime, '{$token}', 121)";

                // Check first so a session that is already gone (or not ours)
                // reports false; the batch re-checks, so the KILL itself only
                // ever runs against the stamped session.
                if (! $db->selectOne($match)) {
                    return false;
                }

                return $db->unprepared("IF EXISTS ({$match}) KILL {$pid}");

            default:
                return false;
        }
    }

    /**
     * CockroachDB: cancel the statements the session $sessionId is running.
     * The session is looked up first because CANCEL QUERIES reports success
     * whether it found a statement or not; a session that is gone (or one this
     * login may not see) answers false, like pg_cancel_backend does for a
     * process it cannot signal. $sessionId was matched against 32 hex digits
     * by the caller and is bound anyway.
     *
     * "true" means the CANCEL was issued for a session that exists, not that a
     * statement stopped. While a read runs inside a server-side cursor,
     * CockroachDB ignores it until the cursor's query has been evaluated, so
     * the statement runs on until the statement timeout cuts it (writes are
     * interrupted at once). docs/compatibility.md, "Stopping a read".
     */
    private function cancelCockroach(DatabaseConnection $db, string $sessionId): bool
    {
        if ((int) $db->scalar('SELECT count(*) FROM [SHOW CLUSTER SESSIONS] WHERE session_id = ?', [$sessionId]) < 1) {
            return false;
        }

        $db->statement('CANCEL QUERIES SELECT query_id FROM [SHOW CLUSTER STATEMENTS] WHERE session_id = ?', [$sessionId]);

        return true;
    }
}
