<?php

namespace Tests\Live;

use App\Enums\DbDriver;
use App\Services\Sql\InspectionResult;
use App\Services\Sql\SqlInspector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A throwaway schema on a real PostgreSQL server for the live guard tests.
 *
 * The server comes from the QUERYPROXY_LIVE_PGSQL_* environment variables
 * (see docker-compose.test.yml). Every test gets its own schema, named
 * qp_live_<random>, with a small fixture; stop() drops it again, so runs
 * neither see nor leave each other's data.
 */
final class LivePgsql
{
    public const ENV_PREFIX = 'QUERYPROXY_LIVE_PGSQL_';

    private const ADMIN = 'live_pgsql_admin';

    private const CONNECTION = 'live_pgsql';

    /** @var list<string> */
    private array $connections = [];

    private function __construct(public readonly string $schema) {}

    public static function configured(): bool
    {
        return self::env('HOST') !== '';
    }

    public static function start(): self
    {
        $live = new self('qp_live_'.bin2hex(random_bytes(6)));

        $live->connection(self::ADMIN, 'public')->statement("CREATE SCHEMA {$live->schema}");

        // Once the schema exists, a failed fixture must not leave it behind:
        // the caller never gets the instance, so it could not stop() it.
        try {
            $db = $live->db();
            $db->statement('CREATE TABLE items (id integer PRIMARY KEY, label text NOT NULL)');
            $db->statement("INSERT INTO items (id, label) VALUES (1, 'one'), (2, 'two'), (3, 'three'), (4, 'four'), (5, 'five')");
            $db->statement('CREATE TABLE parents (id integer PRIMARY KEY)');
            $db->statement('CREATE TABLE children (id integer PRIMARY KEY, parent_id integer NOT NULL REFERENCES parents (id))');
            $db->statement('INSERT INTO parents (id) VALUES (1)');
        } catch (Throwable $e) {
            try {
                $live->stop();
            } catch (Throwable) {
                // Report the fixture failure, not a follow-up cleanup error.
            }

            throw $e;
        }

        return $live;
    }

    public function stop(): void
    {
        foreach ($this->connections as $name) {
            if ($name !== self::ADMIN) {
                DB::purge($name);
            }
        }

        $this->connection(self::ADMIN, 'public')->statement("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        DB::purge(self::ADMIN);
        $this->connections = [];
    }

    /** The connection the tests run their SQL on; search_path is the test schema. */
    public function db(): Connection
    {
        return $this->connection(self::CONNECTION, $this->schema);
    }

    /** A second, independent session on the same schema. */
    public function otherSession(): Connection
    {
        return $this->connection(self::CONNECTION.'_other', $this->schema);
    }

    public function guard(string $sql): InspectionResult
    {
        return app(SqlInspector::class)->inspect($sql, DbDriver::Pgsql);
    }

    /** The value PostgreSQL reports for a setting in the test session right now. */
    public function setting(string $name): string
    {
        return (string) $this->db()->selectOne('SELECT current_setting(?) AS value', [$name])->value;
    }

    /** The backend PID of the test session, read without Laravel's reconnect logic. */
    public function backendPid(): int
    {
        return (int) $this->db()->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn();
    }

    public function isSuperuser(): bool
    {
        return (bool) $this->db()->selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper;
    }

    /** @return list<int> */
    public function itemIds(): array
    {
        return array_map(
            fn (object $row): int => (int) $row->id,
            $this->db()->select('SELECT id FROM items ORDER BY id'),
        );
    }

    public function label(int $id): ?string
    {
        return $this->db()->selectOne('SELECT label FROM items WHERE id = ?', [$id])?->label;
    }

    /**
     * A libpq connection string that reaches this database from inside the server.
     *
     * dblink connects from the PostgreSQL backend, not from the test process,
     * so the string carries everything a password-protected server needs:
     * dbname and user, plus host, port and password when they are configured.
     * Without a host, libpq falls back to the server's local socket.
     *
     * The address the tests use is not always the one the server sees itself
     * on: a container that publishes its port under another number, or is
     * reached through a host name only the test process resolves, needs
     * QUERYPROXY_LIVE_PGSQL_SERVER_HOST / QUERYPROXY_LIVE_PGSQL_SERVER_PORT,
     * which override HOST and PORT for this string only.
     * docker-compose.test.yml avoids the problem by listening on 55432 inside
     * the container as well.
     */
    public function serverSideDsn(): string
    {
        return self::serverSideConnectionString();
    }

    /** serverSideDsn(), read from the environment without starting a schema. */
    public static function serverSideConnectionString(): string
    {
        return self::connectionString([
            'host' => self::env('SERVER_HOST', self::env('HOST')),
            'port' => self::env('SERVER_PORT', self::env('PORT')),
            'dbname' => self::env('DATABASE', 'postgres'),
            'user' => self::env('USERNAME', 'postgres'),
            'password' => self::env('PASSWORD'),
        ]);
    }

    /**
     * A libpq keyword/value connection string; empty values are left out.
     *
     * Every value is single-quoted with backslashes and single quotes
     * backslash-escaped, so spaces, '=' and quotes in a password survive.
     *
     * @param  array<string, string>  $params
     */
    public static function connectionString(array $params): string
    {
        $pairs = [];

        foreach ($params as $keyword => $value) {
            if ($value !== '') {
                $pairs[] = $keyword."='".addcslashes($value, "\\'")."'";
            }
        }

        return implode(' ', $pairs);
    }

    public function quote(string $value): string
    {
        return $this->db()->getPdo()->quote($value);
    }

    private function connection(string $name, string $searchPath): Connection
    {
        if (! in_array($name, $this->connections, true)) {
            config(["database.connections.{$name}" => [
                'driver' => 'pgsql',
                'host' => self::env('HOST'),
                'port' => self::env('PORT', '5432'),
                'database' => self::env('DATABASE', 'postgres'),
                'username' => self::env('USERNAME', 'postgres'),
                'password' => self::env('PASSWORD'),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => $searchPath,
                'sslmode' => 'prefer',
            ]]);
            DB::purge($name);
            $this->connections[] = $name;
        }

        return DB::connection($name);
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = getenv(self::ENV_PREFIX.$key);

        return $value === false || $value === '' ? $default : $value;
    }
}
