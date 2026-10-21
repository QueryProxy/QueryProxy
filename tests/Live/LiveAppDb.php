<?php

namespace Tests\Live;

use App\Services\Audit\AuditTriggers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real application database (pgsql, mysql, mariadb or sqlsrv) for the live
 * audit chain tests.
 *
 * The server comes from the QUERYPROXY_LIVE_APPDB_* environment variables:
 * DRIVER, HOST, PORT, DATABASE, USERNAME, PASSWORD. Without HOST the live
 * tests skip. Only the two audit tables are created, and both are dropped
 * again, so the database can be shared with other live tests.
 *
 * start() drops audit_logs and audit_chain_lock, so it refuses a database
 * whose name does not end in `_test` (queryproxy_test, ...). A server whose
 * only database is a system one (SQL Server `master`) must say so with
 * QUERYPROXY_LIVE_APPDB_ALLOW_ANY_DATABASE=1.
 */
final class LiveAppDb
{
    public const ENV_PREFIX = 'QUERYPROXY_LIVE_APPDB_';

    public const CONNECTION = 'live_appdb';

    public const SECOND_CONNECTION = 'live_appdb_second';

    private const CREATE_MIGRATION = 'database/migrations/2026_09_06_000002_create_audit_logs_table.php';

    private const CHAIN_MIGRATION = 'database/migrations/2026_10_20_000001_add_hash_chain_to_audit_logs_table.php';

    public static function configured(): bool
    {
        return self::env('HOST') !== '';
    }

    public static function driver(): string
    {
        return self::env('DRIVER') ?: 'pgsql';
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(): array
    {
        $driver = self::driver();

        $config = [
            'driver' => $driver,
            'host' => self::env('HOST'),
            'port' => self::env('PORT'),
            'database' => self::env('DATABASE'),
            'username' => self::env('USERNAME'),
            'password' => self::env('PASSWORD'),
            'charset' => $driver === 'pgsql' ? 'utf8' : ($driver === 'sqlsrv' ? 'utf8' : 'utf8mb4'),
            'prefix' => '',
        ];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // As in config/database.php: TIMESTAMP columns are hashed as UTC.
            $config['timezone'] = '+00:00';
        }

        if ($driver === 'sqlsrv') {
            $config['trust_server_certificate'] = true;
            $config['encrypt'] = 'no';
        }

        return $config;
    }

    /**
     * Point the default connection at the live database with a fresh pair of
     * audit tables, the chain migration applied.
     */
    public static function start(): void
    {
        self::assertSafeDatabase();

        config([
            'database.connections.'.self::CONNECTION => self::connectionConfig(),
            'database.connections.'.self::SECOND_CONNECTION => self::connectionConfig(),
        ]);

        DB::setDefaultConnection(self::CONNECTION);

        self::dropAll();

        self::migration(self::CREATE_MIGRATION)->up();
        self::migration(self::CHAIN_MIGRATION)->up();
    }

    /**
     * Never drop tables in a database that does not look like a test one.
     */
    private static function assertSafeDatabase(): void
    {
        $database = self::env('DATABASE');

        if (str_ends_with($database, '_test') || self::env('ALLOW_ANY_DATABASE') === '1') {
            return;
        }

        throw new \RuntimeException(
            "Refusing to drop the audit tables in database \"{$database}\": its name must end in \"_test\" "
            .'(or set '.self::ENV_PREFIX.'ALLOW_ANY_DATABASE=1).'
        );
    }

    public static function stop(string $originalDefault): void
    {
        try {
            self::dropAll();
        } finally {
            DB::purge(self::CONNECTION);
            DB::purge(self::SECOND_CONNECTION);
            DB::setDefaultConnection($originalDefault);
        }
    }

    public static function migration(string $path): object
    {
        return require base_path($path);
    }

    public static function chainMigration(): object
    {
        return self::migration(self::CHAIN_MIGRATION);
    }

    public static function dropAll(): void
    {
        // The pgsql trigger function outlives its table; drop() removes it.
        if (Schema::hasTable('audit_logs')) {
            (new AuditTriggers)->drop();
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('audit_chain_lock');
    }

    private static function env(string $name): string
    {
        $value = getenv(self::ENV_PREFIX.$name);

        return $value === false ? '' : trim($value);
    }
}
