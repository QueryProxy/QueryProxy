<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\DB;

/**
 * SQL Server as the application database.
 *
 * SQL Server refuses a foreign key that "may cause cycles or multiple cascade
 * paths" (error 1785): it rejects the schema when deleting one row of a table
 * could reach another table along two different CASCADE / SET NULL chains,
 * and it does not look at which rows are actually affected. The schema has
 * such chains (teams -> query_requests directly and through connections;
 * users -> connections -> ...; two foreign keys from one table to users), and
 * PostgreSQL, MySQL, MariaDB and SQLite accept them.
 *
 * On SQL Server only, the foreign keys in NO_ACTION are created with
 * `ON DELETE NO ACTION`. Every other driver keeps its CASCADE / SET NULL /
 * RESTRICT exactly as before; existing installations are not touched. What the
 * database would have done for those keys on delete is done by
 * releaseTeamRows() and releaseUserReferences() instead, in the same
 * order and with the same result, called from the Team and User models on
 * SQL Server only.
 *
 * The remaining CASCADE / SET NULL keys form a forest (every table reachable
 * along one path only); SqlServerCascadePathsTest checks that against the real
 * schema, so a new migration that closes a second path fails the test suite
 * and not a SQL Server installation.
 */
final class SqlServerReferentialActions
{
    /**
     * `table.column` of the foreign keys that are NO ACTION on SQL Server.
     *
     * query_requests.user_id and .reviewed_by are RESTRICT on every driver
     * (ADR-018, migration 2026_10_21_000004); SQL Server has no RESTRICT, NO
     * ACTION is its equivalent.
     *
     * @var list<string>
     */
    public const NO_ACTION = [
        // teams -> {query_requests, masking_rules, connection_grant_requests}
        // is also reached through connections; the team's rows are removed
        // by releaseTeamRows() before the team.
        'query_requests.team_id',
        'masking_rules.team_id',
        'connection_grant_requests.team_id',
        // users -> connections and the second foreign keys to users: a user
        // is soft deleted (ADR-018), these only matter for a hard delete,
        // which releaseUserReferences() turns into SET NULL again.
        'connections.created_by',
        'connection_user.granted_by',
        'connection_grant_requests.decided_by',
        'query_requests.user_id',
        'query_requests.reviewed_by',
    ];

    public static function cascadeOnDelete(Blueprint $table, ForeignKeyDefinition $foreign): ForeignKeyDefinition
    {
        return self::noAction($table, $foreign) ? $foreign->noActionOnDelete() : $foreign->cascadeOnDelete();
    }

    public static function nullOnDelete(Blueprint $table, ForeignKeyDefinition $foreign): ForeignKeyDefinition
    {
        return self::noAction($table, $foreign) ? $foreign->noActionOnDelete() : $foreign->nullOnDelete();
    }

    /** SQL Server has no RESTRICT; NO ACTION refuses the delete as well. */
    public static function restrictOnDelete(ForeignKeyDefinition $foreign): ForeignKeyDefinition
    {
        return self::isSqlServer() ? $foreign->noActionOnDelete() : $foreign->restrictOnDelete();
    }

    public static function isSqlServer(?ConnectionInterface $connection = null): bool
    {
        return ($connection ?? DB::connection())->getDriverName() === 'sqlsrv';
    }

    /**
     * Delete what `ON DELETE CASCADE` on teams.id would delete from the three
     * tables whose key is NO ACTION on SQL Server. Run before the team row is
     * deleted. Query requests go first; their chat approval tokens still
     * cascade in the database. Everything else of the team (members,
     * connections with their grants, requests and rules, chat integrations)
     * still cascades.
     */
    public static function releaseTeamRows(ConnectionInterface $connection, int $teamId): void
    {
        $connection->table('query_requests')->where('team_id', $teamId)->delete();
        $connection->table('masking_rules')->where('team_id', $teamId)->delete();
        $connection->table('connection_grant_requests')->where('team_id', $teamId)->delete();
    }

    /**
     * Do what `ON DELETE SET NULL` on users.id would do for the keys that are
     * NO ACTION on SQL Server. Run before a user row is hard deleted.
     *
     * query_requests.user_id and .reviewed_by are RESTRICT everywhere (ADR-018):
     * while a request points at the user, the delete is going to be refused,
     * and elsewhere a refused delete changes nothing. Forced deletion is not
     * one statement here (the model event fires, then the DELETE), so nothing
     * is released in that case; otherwise the references would be lost for a
     * user who stays.
     */
    public static function releaseUserReferences(ConnectionInterface $connection, int $userId): void
    {
        $hasRequests = $connection->table('query_requests')
            ->where(fn ($query) => $query->where('user_id', $userId)->orWhere('reviewed_by', $userId))
            ->exists();

        if ($hasRequests) {
            return;
        }

        $connection->table('connections')->where('created_by', $userId)->update(['created_by' => null]);
        $connection->table('connection_user')->where('granted_by', $userId)->update(['granted_by' => null]);
        $connection->table('connection_grant_requests')->where('decided_by', $userId)->update(['decided_by' => null]);
    }

    private static function noAction(Blueprint $table, ForeignKeyDefinition $foreign): bool
    {
        $columns = (array) $foreign->get('columns');

        return self::isSqlServer() && in_array($table->getTable().'.'.($columns[0] ?? ''), self::NO_ACTION, true);
    }
}
