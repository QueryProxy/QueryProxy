<?php

namespace App\Services\Connections;

use App\Enums\DbDriver;
use App\Models\Connection;
use App\Services\Execution\TargetSession;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class DynamicConnectionFactory
{
    public function __construct(private TargetSession $session, private TlsPemStore $pemStore = new TlsPemStore) {}

    /**
     * Register a runtime Laravel database connection for the given target
     * and return its connection name. Always purge() after use.
     */
    public function configure(Connection $connection): string
    {
        $this->assertAllowedTarget($connection);

        $name = $this->connectionName($connection);

        config(["database.connections.{$name}" => $this->configFor($connection)]);

        return $name;
    }

    /**
     * Register a second runtime connection that logs in with the connection's
     * read-only identity (read_username / read_password) and return its name.
     * Only call this when $connection->hasReadCredentials(). purge() removes
     * it together with the main connection.
     */
    public function configureRead(Connection $connection): string
    {
        $this->assertAllowedTarget($connection);

        $name = $this->readConnectionName($connection);

        config(["database.connections.{$name}" => $this->configFor($connection, readIdentity: true)]);

        return $name;
    }

    /**
     * Guard against connections that target QueryProxy itself: the app's own
     * SQLite file (or any file under the application directory) and the app's
     * own database server would expose stored credentials, password hashes
     * and sessions to the query flow. Operators can extend the blocklist via
     * QUERYPROXY_CONNECTION_HOST_DENYLIST.
     *
     * @return list<string>
     */
    public function targetViolations(Connection $connection): array
    {
        $violations = [];

        if ($connection->driver === DbDriver::Sqlite) {
            $canonical = $this->canonicalPath((string) $connection->database);

            if ($canonical === null) {
                $violations[] = 'SQLite database path could not be resolved (the directory must exist).';

                return $violations;
            }

            $appRoot = realpath(base_path()) ?: base_path();

            if (str_starts_with($canonical, rtrim($appRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                $violations[] = 'SQLite connections may not target files inside the QueryProxy application directory.';
            }

            $allowedDir = config('queryproxy.sqlite_allowed_dir');

            if (is_string($allowedDir) && $allowedDir !== '') {
                $allowedRoot = realpath($allowedDir) ?: $allowedDir;

                if (! str_starts_with($canonical, rtrim($allowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                    $violations[] = 'SQLite connections must live inside the configured QUERYPROXY_SQLITE_ALLOWED_DIR.';
                }
            }

            return $violations;
        }

        $host = strtolower((string) $connection->host);

        foreach ((array) config('queryproxy.connection_host_denylist', []) as $denied) {
            if ($host === strtolower(trim((string) $denied))) {
                $violations[] = "Connections to host \"{$connection->host}\" are blocked on this instance.";
            }
        }

        if ($this->targetsOwnDatabase($connection)) {
            $violations[] = "Connections may not target QueryProxy's own application database.";
        }

        return $violations;
    }

    private function assertAllowedTarget(Connection $connection): void
    {
        $violations = $this->targetViolations($connection);

        if ($violations !== []) {
            throw new InvalidArgumentException($violations[0]);
        }
    }

    private function targetsOwnDatabase(Connection $connection): bool
    {
        $own = (array) config('database.connections.'.config('database.default'), []);

        if (($own['driver'] ?? null) !== $connection->driver->value) {
            return false;
        }

        $loopback = ['localhost', '127.0.0.1', '::1'];
        $ownHost = strtolower((string) ($own['host'] ?? ''));
        $host = strtolower((string) $connection->host);
        $sameHost = $ownHost === $host
            || (in_array($ownHost, $loopback, true) && in_array($host, $loopback, true));

        $ownPort = (int) ($own['port'] ?? $connection->driver->defaultPort());
        $port = (int) ($connection->port ?: $connection->driver->defaultPort());

        return $sameHost
            && $ownPort === $port
            && strcasecmp((string) ($own['database'] ?? ''), (string) $connection->database) === 0;
    }

    private function canonicalPath(string $path): ?string
    {
        $resolved = realpath($path);

        if ($resolved !== false) {
            return $resolved;
        }

        $dir = realpath(dirname($path));

        return $dir === false ? null : $dir.DIRECTORY_SEPARATOR.basename($path);
    }

    /** Strip connection identifiers / credentials out of a driver error message. */
    public function redactError(?string $message, Connection $connection): ?string
    {
        if ($message === null) {
            return null;
        }

        $secrets = array_values(array_filter([
            $connection->password,
            $connection->username,
            $connection->read_password,
            $connection->read_username,
            $connection->host,
            $connection->database,
        ], fn ($value) => is_string($value) && $value !== ''));

        return $secrets === [] ? $message : str_replace($secrets, '[redacted]', $message);
    }

    public function purge(Connection $connection): void
    {
        foreach ([$this->connectionName($connection), $this->readConnectionName($connection)] as $name) {
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }
    }

    /**
     * Try to connect and run a trivial probe query. `tls` reports the
     * configured TLS state and, where the server lets the login see it,
     * whether the live session is actually encrypted.
     *
     * @return array{ok: bool, latency_ms: float|null, error: string|null, tls: array{state: string, mode: string|null, encrypted: bool|null}}
     */
    public function test(Connection $connection): array
    {
        $readProbe = false;
        $tls = ['state' => $connection->tlsState(), 'mode' => $connection->tlsMode(), 'encrypted' => null];

        try {
            $name = $this->configure($connection);

            $start = hrtime(true);
            DB::connection($name)->select($this->probeQuery($connection->driver));
            $latency = (hrtime(true) - $start) / 1_000_000;

            $tls['encrypted'] = $this->sessionIsEncrypted($name, $connection->driver);

            if ($connection->hasReadCredentials() && $connection->driver !== DbDriver::Sqlite) {
                $readProbe = true;
                DB::connection($this->configureRead($connection))->select($this->probeQuery($connection->driver));
            }

            return ['ok' => true, 'latency_ms' => round($latency, 1), 'error' => null, 'tls' => $tls];
        } catch (Throwable $e) {
            $error = $this->redactError($e->getMessage(), $connection);

            return [
                'ok' => false,
                'latency_ms' => null,
                'error' => $readProbe ? 'Read credentials: '.$error : $error,
                'tls' => $tls,
            ];
        } finally {
            $this->purge($connection);
        }
    }

    /**
     * Ask the server whether the current session is encrypted. Returns null
     * when the driver has no such view or the login may not read it.
     */
    private function sessionIsEncrypted(string $name, DbDriver $driver): ?bool
    {
        try {
            $connection = DB::connection($name);

            return match ($driver) {
                DbDriver::Pgsql => $this->truthy($connection->selectOne('select ssl from pg_stat_ssl where pid = pg_backend_pid()')?->ssl ?? null),
                DbDriver::Mysql, DbDriver::Mariadb => ($cipher = $connection->selectOne("show session status like 'Ssl_cipher'")) === null
                    ? null
                    : trim((string) (((array) $cipher)['Value'] ?? '')) !== '',
                DbDriver::Sqlsrv => $this->truthy($connection->selectOne('select encrypt_option from sys.dm_exec_connections where session_id = @@spid')?->encrypt_option ?? null),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function truthy(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public function connectionName(Connection $connection): string
    {
        return 'queryproxy_target_'.$connection->id;
    }

    public function readConnectionName(Connection $connection): string
    {
        return $this->connectionName($connection).'_read';
    }

    /**
     * @param  bool  $readIdentity  log in as read_username / read_password instead of the main identity
     * @return array<string, mixed>
     */
    public function configFor(Connection $connection, bool $readIdentity = false): array
    {
        $driver = $connection->driver;
        $options = $this->session->pdoOptions($driver);

        if ($driver === DbDriver::Sqlite) {
            return [
                'driver' => 'sqlite',
                'database' => $connection->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'options' => $options,
            ];
        }

        $config = [
            'driver' => $driver->value,
            'host' => $connection->host,
            'port' => (int) ($connection->port ?: $driver->defaultPort()),
            'database' => $connection->database,
            'username' => $readIdentity ? $connection->read_username : $connection->username,
            'password' => ($readIdentity ? $connection->read_password : $connection->password) ?? '',
            'prefix' => '',
        ];

        if ($options !== []) {
            $config['options'] = $options;
        }

        return match ($driver) {
            DbDriver::Mysql, DbDriver::Mariadb => $this->withMysqlTls($config + [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ], $connection),
            DbDriver::Pgsql => $this->withPgsqlTls($config + [
                'charset' => 'utf8',
                'search_path' => 'public',
            ], $connection),
            DbDriver::Sqlsrv => $this->withSqlsrvTls($config + [
                'charset' => 'utf8',
            ], $connection),
            default => $config,
        };
    }

    /**
     * PostgreSQL: sslmode is always explicit; libpq enforces `require` and up
     * (no plaintext fallback). PEM files are passed by path.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withPgsqlTls(array $config, Connection $connection): array
    {
        $config['sslmode'] = $connection->tlsMode();

        $paths = $this->pemStore->sync($connection);

        foreach (['ca' => 'sslrootcert', 'cert' => 'sslcert', 'key' => 'sslkey'] as $kind => $key) {
            if (isset($paths[$kind])) {
                $config[$key] = $paths[$kind];
            }
        }

        return $config;
    }

    /**
     * MySQL/MariaDB (pdo_mysql / mysqlnd). Setting only VERIFY_SERVER_CERT does
     * NOT switch TLS on: mysqlnd then logs in over plaintext. TLS is requested
     * by any of CA / cert / key / cipher, and when the server offers no TLS
     * mysqlnd aborts before sending credentials. So a trigger option is always
     * present when TLS is required, and verification is always explicit.
     * With no mode set (existing connections) nothing is added: driver default.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withMysqlTls(array $config, Connection $connection): array
    {
        if ($connection->tlsMode() !== Connection::TLS_MODE_REQUIRED) {
            return $config;
        }

        $paths = $this->pemStore->sync($connection);
        $options = [];

        foreach (['ca' => 'SSL_CA', 'cert' => 'SSL_CERT', 'key' => 'SSL_KEY'] as $kind => $attribute) {
            if (isset($paths[$kind])) {
                $options[$this->mysqlAttribute($attribute)] = $paths[$kind];
            }
        }

        if ($options === []) {
            $options[$this->mysqlAttribute('SSL_CIPHER')] = 'DEFAULT';
        }

        $options[$this->mysqlAttribute('SSL_VERIFY_SERVER_CERT')] = $connection->tlsFlag('verify_server_cert');

        $config['options'] = array_replace($config['options'] ?? [], $options);

        return $config;
    }

    /**
     * SQL Server (pdo_sqlsrv). Values are `true` / `false`: those are what the
     * Microsoft driver documents for Encrypt and TrustServerCertificate.
     * Laravel falls back to the dblib DSN, which has no Encrypt, when pdo_sqlsrv
     * is missing; that would be a silent plaintext login, so it is refused.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withSqlsrvTls(array $config, Connection $connection): array
    {
        if ($connection->tlsMode() !== Connection::TLS_MODE_REQUIRED) {
            return $config;
        }

        if (! in_array('sqlsrv', $this->availablePdoDrivers(), true)) {
            throw new RuntimeException('This SQL Server connection requires TLS, which needs the pdo_sqlsrv extension; it is not installed.');
        }

        $config['encrypt'] = 'true';
        $config['trust_server_certificate'] = $connection->tlsFlag('trust_server_certificate') ? 'true' : 'false';

        return $config;
    }

    /** @return list<string> */
    protected function availablePdoDrivers(): array
    {
        return PDO::getAvailableDrivers();
    }

    private function mysqlAttribute(string $name): int
    {
        foreach (['Pdo\\Mysql::ATTR_'.$name, 'PDO::MYSQL_ATTR_'.$name] as $constant) {
            if (defined($constant)) {
                return constant($constant);
            }
        }

        throw new RuntimeException('The pdo_mysql extension is required for MySQL/MariaDB TLS options.');
    }

    private function probeQuery(DbDriver $driver): string
    {
        return match ($driver) {
            DbDriver::Pgsql, DbDriver::Mysql, DbDriver::Mariadb, DbDriver::Sqlite, DbDriver::Sqlsrv => 'select 1',
        };
    }
}
