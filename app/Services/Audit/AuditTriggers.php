<?php

namespace App\Services\Audit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Database triggers that reject UPDATE and DELETE on the audit table
 * (ADR-020). The model events only guard Eloquent; these guard the table.
 */
class AuditTriggers
{
    public const MESSAGE = 'Audit logs are immutable.';

    private const TABLE = 'audit_logs';

    public function install(?ConnectionInterface $connection = null): void
    {
        $connection ??= DB::connection();

        foreach ($this->installStatements($connection->getDriverName()) as $statement) {
            $connection->unprepared($statement);
        }
    }

    public function drop(?ConnectionInterface $connection = null): void
    {
        $connection ??= DB::connection();

        foreach ($this->dropStatements($connection->getDriverName()) as $statement) {
            $connection->unprepared($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function installStatements(string $driver): array
    {
        $table = self::TABLE;
        $message = self::MESSAGE;

        return match ($driver) {
            'sqlite' => [
                "CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                "CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            ],
            'mysql', 'mariadb' => [
                "CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ],
            'pgsql' => [
                "CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '{$message}'; END; \$\$ LANGUAGE plpgsql",
                "CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable()",
                "CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable()",
                "CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_immutable()",
            ],
            'sqlsrv' => [
                "CREATE TRIGGER audit_logs_no_update ON {$table} INSTEAD OF UPDATE AS BEGIN THROW 50001, '{$message}', 1; END",
                "CREATE TRIGGER audit_logs_no_delete ON {$table} INSTEAD OF DELETE AS BEGIN THROW 50001, '{$message}', 1; END",
            ],
            default => throw new RuntimeException("Cannot enforce audit log immutability on database driver [{$driver}]."),
        };
    }

    /**
     * @return list<string>
     */
    private function dropStatements(string $driver): array
    {
        $table = self::TABLE;

        return match ($driver) {
            'sqlite', 'mysql', 'mariadb', 'sqlsrv' => [
                'DROP TRIGGER IF EXISTS audit_logs_no_update',
                'DROP TRIGGER IF EXISTS audit_logs_no_delete',
            ],
            'pgsql' => [
                "DROP TRIGGER IF EXISTS audit_logs_no_update ON {$table}",
                "DROP TRIGGER IF EXISTS audit_logs_no_delete ON {$table}",
                "DROP TRIGGER IF EXISTS audit_logs_no_truncate ON {$table}",
                'DROP FUNCTION IF EXISTS audit_logs_immutable()',
            ],
            default => throw new RuntimeException("Unsupported database driver [{$driver}] for audit log triggers."),
        };
    }
}
