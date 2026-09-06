<?php

namespace App\Services\Connections;

use App\Enums\DbDriver;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DynamicConnectionFactory
{
    /**
     * Register a runtime Laravel database connection for the given target
     * and return its connection name. Always purge() after use.
     */
    public function configure(Connection $connection): string
    {
        $name = $this->connectionName($connection);

        config(["database.connections.{$name}" => $this->configFor($connection)]);

        return $name;
    }

    public function purge(Connection $connection): void
    {
        $name = $this->connectionName($connection);

        DB::purge($name);
        config(["database.connections.{$name}" => null]);
    }

    /**
     * Try to connect and run a trivial probe query.
     *
     * @return array{ok: bool, latency_ms: float|null, error: string|null}
     */
    public function test(Connection $connection): array
    {
        $name = $this->configure($connection);

        try {
            $start = hrtime(true);
            DB::connection($name)->select($this->probeQuery($connection->driver));
            $latency = (hrtime(true) - $start) / 1_000_000;

            return ['ok' => true, 'latency_ms' => round($latency, 1), 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => null, 'error' => $e->getMessage()];
        } finally {
            $this->purge($connection);
        }
    }

    public function connectionName(Connection $connection): string
    {
        return 'queryproxy_target_'.$connection->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function configFor(Connection $connection): array
    {
        $driver = $connection->driver;

        if ($driver === DbDriver::Sqlite) {
            return [
                'driver' => 'sqlite',
                'database' => $connection->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        $config = [
            'driver' => $driver->value,
            'host' => $connection->host,
            'port' => (int) ($connection->port ?: $driver->defaultPort()),
            'database' => $connection->database,
            'username' => $connection->username,
            'password' => $connection->password ?? '',
            'prefix' => '',
        ];

        return match ($driver) {
            DbDriver::Mysql, DbDriver::Mariadb => $config + [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
            DbDriver::Pgsql => $config + [
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            DbDriver::Sqlsrv => $config + [
                'charset' => 'utf8',
            ],
            default => $config,
        };
    }

    private function probeQuery(DbDriver $driver): string
    {
        return match ($driver) {
            DbDriver::Pgsql, DbDriver::Mysql, DbDriver::Mariadb, DbDriver::Sqlite, DbDriver::Sqlsrv => 'select 1',
        };
    }
}
