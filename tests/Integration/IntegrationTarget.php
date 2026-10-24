<?php

namespace Tests\Integration;

use App\Enums\DbDriver;
use App\Enums\QueryRequestStatus;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Execution\QueryExecutor;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One real target database (PostgreSQL, MySQL, MariaDB or SQL Server) for the
 * integration tests, plus the QueryProxy objects that point at it.
 *
 * The server comes from the environment (docker-compose.integration.yml sets
 * it up, CI exports the variables). Without QUERYPROXY_IT_HOST the tests skip.
 *
 *   QUERYPROXY_IT_DRIVER      pgsql | mysql | mariadb | sqlsrv
 *   QUERYPROXY_IT_HOST        host of the main server
 *   QUERYPROXY_IT_PORT        its port
 *   QUERYPROXY_IT_DATABASE    database name (created here on SQL Server)
 *   QUERYPROXY_IT_USERNAME    administrator login (creates tables, logins, signals sessions)
 *   QUERYPROXY_IT_PASSWORD
 *   QUERYPROXY_IT_TLS_ON_PORT   port of a server of the same driver that offers TLS
 *   QUERYPROXY_IT_TLS_OFF_PORT  port of a server of the same driver that does not
 *   QUERYPROXY_IT_TLS_CA        path of the CA certificate of the TLS_ON server (PEM)
 *
 * Every test works on tables of its own (qp_it_<random>_items, ...), dropped
 * again by stop(), so runs neither see nor leave each other's data. The
 * application database is a throwaway SQLite file: the executor runs in this
 * process or, where a test has to stop it from outside, in a child process
 * (executor_process.php) that opens the same file.
 */
final class IntegrationTarget
{
    public const ENV_PREFIX = 'QUERYPROXY_IT_';

    public const SKIP_MESSAGE = 'Set QUERYPROXY_IT_DRIVER and QUERYPROXY_IT_HOST (see docker-compose.integration.yml) to run the integration tests.';

    /** Rows of the nums table; three of them cross-joined run for minutes. */
    public const NUMS_ROWS = 3000;

    /** Password of the read-only login the tests create. */
    private const READER_PASSWORD = 'Qp!Reader_9z';

    private const APP_CONNECTION = 'it_app';

    private static ?string $appTemplate = null;

    private static bool $databaseEnsured = false;

    public readonly string $suffix;

    public readonly Team $team;

    public readonly User $owner;

    private string $originalDefault;

    private string $appDbFile;

    private string $resultDir;

    /** @var list<string> */
    private array $tables = [];

    /** @var list<string> names of the extra admin sessions opened */
    private array $sessions = [];

    /** @var array{username: string, password: string}|null */
    private ?array $reader = null;

    /** @var list<string> sqlsrv logins created by login() */
    private array $logins = [];

    private function __construct()
    {
        $this->suffix = 'qp_it_'.bin2hex(random_bytes(4));
    }

    public static function configured(): bool
    {
        return self::env('HOST') !== '' && self::env('DRIVER') !== '';
    }

    /**
     * CI sets QUERYPROXY_IT_REQUIRED: there a test that finds its server (or
     * CA file, or TLS port) missing is a broken job, not something to skip,
     * or a mistyped variable would turn the whole job green with every test
     * skipped. Locally, and in the ordinary test job, nothing is required.
     */
    public static function failIfRequired(string $message): void
    {
        if (self::env('REQUIRED') !== '') {
            throw new AssertionFailedError('QUERYPROXY_IT_REQUIRED is set, but: '.$message);
        }
    }

    public static function env(string $key, string $default = ''): string
    {
        $value = getenv(self::ENV_PREFIX.$key);

        return $value === false || trim($value) === '' ? $default : trim($value);
    }

    public static function driver(): DbDriver
    {
        return DbDriver::from(self::env('DRIVER'));
    }

    public static function isMysqlFamily(): bool
    {
        return in_array(self::driver(), [DbDriver::Mysql, DbDriver::Mariadb], true);
    }

    /**
     * Fixtures: the items table (5 rows) and an empty sink table; with $nums
     * also the nums table the long-running statements are built from.
     */
    public static function start(bool $nums = false): self
    {
        $target = new self;

        // Once anything exists on the server, a failed set-up must not leave it
        // behind: the caller never gets the instance, so it could not stop() it.
        try {
            self::ensureDatabase();
            $target->useAppDatabase();

            $target->resultDir = sys_get_temp_dir().'/'.$target->suffix.'_results';
            mkdir($target->resultDir, 0700, true);
            config(['filesystems.disks.local.root' => $target->resultDir]);
            Storage::forgetDisk('local');

            $target->createFixtures($nums);

            $target->team = Team::factory()->create();
            $target->owner = User::factory()->create();
            $target->team->users()->attach($target->owner, ['role' => 'developer']);
        } catch (Throwable $e) {
            try {
                $target->stop();
            } catch (Throwable) {
                // Report the set-up failure, not a follow-up cleanup error.
            }

            throw $e;
        }

        return $target;
    }

    public function stop(): void
    {
        try {
            Connection::query()->get()->each(fn (Connection $connection) => $connection->delete());
        } catch (Throwable) {
            // Only here so the stored TLS files go; the database file is dropped below.
        }

        foreach ($this->sessions as $name) {
            DB::purge($name);
        }

        try {
            // A test that tries to create it (a DDL statement in a read request) must not leave it behind.
            $this->admin()->statement('DROP TABLE IF EXISTS '.$this->table('created'));

            foreach (array_reverse($this->tables) as $table) {
                $this->admin()->statement("DROP TABLE IF EXISTS {$table}");
            }

            $this->dropReader();
            $this->dropLogins();
        } finally {
            DB::purge('it_admin');

            if (isset($this->originalDefault)) {
                DB::purge(self::APP_CONNECTION);
                DB::setDefaultConnection($this->originalDefault);
            }

            if (isset($this->appDbFile)) {
                @unlink($this->appDbFile);
            }

            if (isset($this->resultDir)) {
                self::removeDirectory($this->resultDir);
            }
        }
    }

    /** Name of one of this test's tables: items, nums, sink, big. */
    public function table(string $key): string
    {
        return $this->suffix.'_'.$key;
    }

    /** Replace {items}, {nums}, {sink}, {big} and {created} with this test's table names. */
    public function sql(string $template): string
    {
        return preg_replace_callback('/\{(items|nums|sink|big|created)\}/', fn (array $m): string => $this->table($m[1]), $template);
    }

    // ---- administrator sessions -------------------------------------------------

    /** The administrator session the tests use for fixtures and server-side checks. */
    public function admin(): DatabaseConnection
    {
        return $this->session('admin');
    }

    /** An independent administrator session (its own server-side connection). */
    public function session(string $label): DatabaseConnection
    {
        $name = $label === 'admin' ? 'it_admin' : 'it_admin_'.$label;

        if ($name !== 'it_admin' && ! in_array($name, $this->sessions, true)) {
            $this->sessions[] = $name;
        }

        config(["database.connections.{$name}" => config("database.connections.{$name}") ?? self::adminConfig()]);

        return DB::connection($name);
    }

    /**
     * Connection settings for the administrator login. Deliberately plain
     * configuration, independent of DynamicConnectionFactory (the code under
     * test), but encrypted where the server insists on it.
     *
     * @return array<string, mixed>
     */
    public static function adminConfig(?string $port = null): array
    {
        $driver = self::driver();

        $config = [
            'driver' => $driver->value,
            'host' => self::env('HOST'),
            'port' => $port ?? self::env('PORT'),
            'database' => self::env('DATABASE', 'queryproxy_test'),
            'username' => self::env('USERNAME'),
            'password' => self::env('PASSWORD'),
            'prefix' => '',
        ];

        return match ($driver) {
            DbDriver::Pgsql => $config + ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'],
            DbDriver::Mysql => $config + [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                // MySQL 8.4 accounts use caching_sha2_password, whose first login needs TLS.
                'options' => [
                    self::mysqlAttribute('SSL_CIPHER') => 'DEFAULT',
                    self::mysqlAttribute('SSL_VERIFY_SERVER_CERT') => false,
                ],
            ],
            DbDriver::Mariadb => $config + ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            DbDriver::Sqlsrv => $config + ['charset' => 'utf8', 'encrypt' => 'true', 'trust_server_certificate' => 'true'],
            default => throw new RuntimeException('Unsupported integration driver.'),
        };
    }

    // ---- QueryProxy objects -----------------------------------------------------

    /**
     * A stored connection to the main server (or, with $port, to another server
     * of the same driver), with the TLS settings the functional tests need.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $tls  replaces the functional TLS settings
     */
    public function connection(array $attributes = [], ?array $tls = null, bool $withReader = false): Connection
    {
        $attributes += [
            'team_id' => $this->team->id,
            'driver' => self::driver(),
            'host' => self::env('HOST'),
            'port' => self::env('PORT'),
            'database' => self::env('DATABASE', 'queryproxy_test'),
            'username' => self::env('USERNAME'),
            'password' => self::env('PASSWORD'),
            'options' => ['tls' => $tls ?? self::functionalTls()],
        ];

        if ($withReader) {
            $reader = $this->reader();
            $attributes += ['read_username' => $reader['username'], 'read_password' => $reader['password']];
        }

        return Connection::factory()->create($attributes);
    }

    /**
     * TLS settings of the connections the functional tests use. The main
     * PostgreSQL and MariaDB servers have no TLS, MySQL insists on it for the
     * first login of an account, SQL Server's certificate is self-signed.
     *
     * @return array<string, mixed>
     */
    public static function functionalTls(): array
    {
        return match (self::driver()) {
            DbDriver::Pgsql => ['mode' => 'disable'],
            DbDriver::Mysql => ['mode' => Connection::TLS_MODE_REQUIRED, 'verify_server_cert' => false],
            DbDriver::Mariadb => ['mode' => 'disabled'],
            DbDriver::Sqlsrv => ['mode' => Connection::TLS_MODE_REQUIRED, 'trust_server_certificate' => true],
            default => [],
        };
    }

    /** @param  array<string, mixed>  $attributes */
    public function request(Connection $connection, string $sql, string $type = 'read', array $attributes = []): QueryRequest
    {
        $sql = $this->sql($sql);

        return QueryRequest::factory()->create($attributes + [
            'team_id' => $this->team->id,
            'connection_id' => $connection->id,
            'user_id' => $this->owner->id,
            'sql_original' => $sql,
            'sql_prepared' => $sql,
            'statement_count' => 1,
            'type' => $type,
            'status' => QueryRequestStatus::Queued,
        ]);
    }

    /** Run the request through the real executor in this process. */
    public function execute(QueryRequest $request): QueryRequest
    {
        app(QueryExecutor::class)->execute($request);

        return $request->fresh();
    }

    /**
     * The rows of a completed read, every value as a string (the drivers
     * disagree on whether an integer column comes back as int or string).
     *
     * @return list<list<string>>
     */
    public function resultRows(QueryRequest $request): array
    {
        $contents = Storage::disk((string) $request->result_disk)->get((string) $request->result_path);
        $rows = [];

        foreach (preg_split('/\R/', trim((string) $contents), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $rows[] = array_map(fn (mixed $value): string => (string) $value, json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }

        return $rows;
    }

    // ---- fixtures ---------------------------------------------------------------

    public function itemIds(): array
    {
        return array_map(fn (object $row): int => (int) $row->id, $this->admin()->select('SELECT id FROM '.$this->table('items').' ORDER BY id'));
    }

    public function itemLabel(int $id): ?string
    {
        return $this->admin()->selectOne('SELECT label FROM '.$this->table('items').' WHERE id = ?', [$id])?->label;
    }

    public function sinkCount(): int
    {
        return (int) $this->admin()->selectOne('SELECT COUNT(*) AS n FROM '.$this->table('sink'))->n;
    }

    /** COUNT(*) as the driver spells the 64-bit one (a cross join of three nums overflows an int on SQL Server). */
    public function bigCount(): string
    {
        return self::driver() === DbDriver::Sqlsrv ? 'COUNT_BIG(*)' : 'COUNT(*)';
    }

    /** A read that runs for minutes on every server and can be interrupted. */
    public function heavyRead(): string
    {
        return $this->sql("SELECT {$this->bigCount()} AS total FROM {nums} a, {nums} b, {nums} c");
    }

    /** A write whose long part (the SELECT) comes before the first row is stored. */
    public function heavyWrite(): string
    {
        return $this->sql("INSERT INTO {sink} (n) SELECT {$this->bigCount()} FROM {nums} a, {nums} b, {nums} c");
    }

    /**
     * Create the big table: $rows rows of an id and a 1 KB text, built from
     * nums with one INSERT ... SELECT. Needs the nums fixture.
     */
    public function createBig(int $rows): void
    {
        $big = $this->table('big');
        $nums = $this->table('nums');
        $admin = $this->admin();

        $text = match (self::driver()) {
            DbDriver::Pgsql => 'text',
            DbDriver::Sqlsrv => 'varchar(max)',
            default => 'text',
        };

        $repeat = match (self::driver()) {
            DbDriver::Sqlsrv => "REPLICATE(CAST('x' AS varchar(max)), 1024)",
            DbDriver::Pgsql => "repeat('x', 1024)",
            default => "REPEAT('x', 1024)",
        };

        $this->track($big);
        $admin->statement("CREATE TABLE {$big} (id INT NOT NULL PRIMARY KEY, payload {$text} NOT NULL)");

        $perBlock = self::NUMS_ROWS;
        $admin->statement(
            "INSERT INTO {$big} (id, payload) SELECT (a.n - 1) * {$perBlock} + b.n, {$repeat} FROM {$nums} a CROSS JOIN {$nums} b WHERE (a.n - 1) * {$perBlock} + b.n <= {$rows}"
        );

        $this->grantReader($big);
    }

    // ---- read-only login --------------------------------------------------------

    /** @return array{username: string, password: string} */
    public function reader(): array
    {
        return $this->reader ??= $this->createReader();
    }

    /** Let the read-only login read $table. */
    public function grantReader(string $table): void
    {
        if ($this->reader === null) {
            return;
        }

        $user = $this->reader['username'];
        $admin = $this->admin();
        $database = self::env('DATABASE', 'queryproxy_test');

        match (self::driver()) {
            DbDriver::Pgsql, DbDriver::Sqlsrv => $admin->statement("GRANT SELECT ON {$table} TO {$user}"),
            DbDriver::Mysql, DbDriver::Mariadb => $admin->statement("GRANT SELECT ON `{$database}`.`{$table}` TO '{$user}'@'%'"),
            default => null,
        };
    }

    /** @return array{username: string, password: string} */
    private function createReader(): array
    {
        $user = 'qp_it_r_'.substr($this->suffix, -8);
        $password = self::READER_PASSWORD;
        $admin = $this->admin();

        match (self::driver()) {
            DbDriver::Pgsql => $admin->statement("CREATE ROLE {$user} LOGIN PASSWORD '{$password}'"),
            DbDriver::Mysql, DbDriver::Mariadb => $admin->statement("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$password}'"),
            DbDriver::Sqlsrv => $this->createSqlsrvLogin($user, $password),
            default => null,
        };

        $this->reader = ['username' => $user, 'password' => $password];

        foreach ($this->tables as $table) {
            $this->grantReader($table);
        }

        return $this->reader;
    }

    private function dropReader(): void
    {
        if ($this->reader === null) {
            return;
        }

        $user = $this->reader['username'];
        $admin = $this->admin();

        try {
            match (self::driver()) {
                DbDriver::Pgsql => $this->dropPgsqlRole($user),
                DbDriver::Mysql, DbDriver::Mariadb => $admin->statement("DROP USER IF EXISTS '{$user}'@'%'"),
                DbDriver::Sqlsrv => $this->dropSqlsrvLogin($user),
                default => null,
            };
        } catch (Throwable) {
            // A leftover role on a throwaway server is not worth failing a test for.
        }

        $this->reader = null;
    }

    private function dropPgsqlRole(string $role): void
    {
        $this->admin()->statement("DROP OWNED BY {$role}");
        $this->admin()->statement("DROP ROLE IF EXISTS {$role}");
    }

    /**
     * A SQL Server login with a user in the test database. $language is the
     * login's default language, which decides how it reads dates.
     */
    public function createSqlsrvLogin(string $name, string $password, ?string $language = null): void
    {
        $admin = $this->admin();
        $with = "PASSWORD = '{$password}', CHECK_POLICY = OFF".($language !== null ? ", DEFAULT_LANGUAGE = {$language}" : '');

        $admin->statement("CREATE LOGIN {$name} WITH {$with}");
        $admin->statement("CREATE USER {$name} FOR LOGIN {$name}");
        $this->logins[] = $name;
    }

    private function dropSqlsrvLogin(string $name): void
    {
        $admin = $this->admin();

        // A login with a session still open cannot be dropped; end the sessions first.
        foreach ($admin->select('SELECT session_id FROM sys.dm_exec_sessions WHERE login_name = ?', [$name]) as $row) {
            try {
                $admin->unprepared('KILL '.(int) $row->session_id);
            } catch (Throwable) {
                // Already gone.
            }
        }

        $admin->statement("IF USER_ID(N'{$name}') IS NOT NULL DROP USER {$name}");
        $admin->statement("IF SUSER_ID(N'{$name}') IS NOT NULL DROP LOGIN {$name}");
    }

    private function dropLogins(): void
    {
        foreach ($this->logins as $login) {
            try {
                $this->dropSqlsrvLogin($login);
            } catch (Throwable) {
                // See dropReader().
            }
        }

        $this->logins = [];
    }

    /** Remember a table so stop() drops it. */
    public function track(string $table): void
    {
        $this->tables[] = $table;
    }

    // ---- child processes --------------------------------------------------------

    /**
     * Start the real executor for $request in a child process, the way a
     * queue worker would run it. It opens the same application database file.
     *
     * @param  array<string, string>  $env  extra environment, e.g. QUERYPROXY_SELECT_HARD_LIMIT
     */
    public function startExecutor(QueryRequest $request, array $env = [], float $timeout = 600): Process
    {
        $process = new Process(
            [PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Integration/executor_process.php'), (string) $request->id, $this->resultDir],
            base_path(),
            $env + [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->appDbFile,
                'DB_URL' => '',
            ],
        );

        $process->setTimeout($timeout)->start();

        return $process;
    }

    /** Wait until the executor has started the statement and recorded its session id. */
    public function waitUntilRunning(QueryRequest $request, Process $executor, float $seconds = 30): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            usleep(100_000);

            $running = QueryRequest::whereKey($request->id)
                ->where('status', QueryRequestStatus::Running)
                ->whereNotNull('backend_pid')
                ->exists();
        } while (! $running && $executor->isRunning() && microtime(true) < $deadline);

        return $running;
    }

    public static function waitForExit(Process $process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        return ! $process->isRunning();
    }

    /**
     * Wait for a child process to finish and, while it runs, sample its
     * anonymous resident memory from outside (Linux /proc). Returns the highest
     * value seen in bytes, 0 where /proc is not available.
     */
    public static function waitSamplingMemory(Process $process): int
    {
        $peak = 0;

        do {
            $process->checkTimeout();
            $peak = max($peak, self::anonymousMemory((int) $process->getPid()));
            usleep(50_000);
        } while ($process->isRunning());

        return $peak;
    }

    /** RssAnon of process $pid in bytes (heap, malloc, Zend MM; not file-backed pages), 0 when unknown. */
    public static function anonymousMemory(int $pid): int
    {
        $status = $pid > 0 ? @file_get_contents("/proc/{$pid}/status") : false;

        return is_string($status) ? self::parseAnonymousMemory($status) : 0;
    }

    public static function parseAnonymousMemory(string $status): int
    {
        return preg_match('/^RssAnon:\s+(\d+)\s+kB$/m', $status, $match) === 1 ? (int) $match[1] * 1024 : 0;
    }

    /** Whether the server still runs a statement on session $id. */
    public function statementRunning(int $id): bool
    {
        $admin = $this->admin();

        return match (self::driver()) {
            DbDriver::Pgsql => (int) $admin->selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE pid = ? AND state <> 'idle'", [$id])->n > 0,
            DbDriver::Mysql, DbDriver::Mariadb => (int) $admin->selectOne("SELECT COUNT(*) AS n FROM information_schema.processlist WHERE id = ? AND command <> 'Sleep'", [$id])->n > 0,
            DbDriver::Sqlsrv => (int) $admin->selectOne('SELECT COUNT(*) AS n FROM sys.dm_exec_requests WHERE session_id = ?', [$id])->n > 0,
            default => false,
        };
    }

    /** Whether the server still has session $id at all. */
    public function sessionExists(int $id): bool
    {
        $admin = $this->admin();

        return match (self::driver()) {
            DbDriver::Pgsql => (int) $admin->selectOne('SELECT count(*) AS n FROM pg_stat_activity WHERE pid = ?', [$id])->n > 0,
            DbDriver::Mysql, DbDriver::Mariadb => (int) $admin->selectOne('SELECT COUNT(*) AS n FROM information_schema.processlist WHERE id = ?', [$id])->n > 0,
            DbDriver::Sqlsrv => (int) $admin->selectOne('SELECT COUNT(*) AS n FROM sys.dm_exec_sessions WHERE session_id = ?', [$id])->n > 0,
            default => false,
        };
    }

    // ---- TLS helpers ------------------------------------------------------------

    /** The CA certificate (PEM) of the TLS-enabled server, or null when none is configured. */
    public static function caPem(): ?string
    {
        $path = self::env('TLS_CA');

        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $pem = @file_get_contents($path);

        return $pem === false ? null : $pem;
    }

    /**
     * Whether the server on the main host's $port offers TLS, read from the
     * first bytes it sends, with no login and independent of how the server
     * was configured: PostgreSQL answers an SSLRequest with 'S' or 'N', a
     * MySQL/MariaDB greeting carries the CLIENT_SSL capability flag. This is
     * the precondition of the "no TLS" tests, so a server that turns out to
     * offer TLS (a default of a new image version, an ignored start-up flag)
     * is reported as a broken test set-up and not as an application failure.
     */
    public static function offersTls(string $port): bool
    {
        $socket = @fsockopen(self::env('HOST'), (int) $port, $errno, $error, 5);

        if ($socket === false) {
            throw new RuntimeException("Cannot reach the server on port {$port}: {$error}");
        }

        stream_set_timeout($socket, 5);

        try {
            if (self::driver() === DbDriver::Pgsql) {
                fwrite($socket, pack('NN', 8, 80877103)); // SSLRequest

                return fread($socket, 1) === 'S';
            }

            $header = (string) fread($socket, 4);
            $length = (int) (unpack('V', substr($header, 0, 3)."\0")[1] ?? 0);

            return (self::greetingCapabilities((string) fread($socket, $length)) & 0x0800) !== 0; // CLIENT_SSL
        } finally {
            fclose($socket);
        }
    }

    /**
     * The low 16 bits of the capability flags in a MySQL/MariaDB initial
     * handshake packet: protocol version (1 byte), server version (NUL
     * terminated), connection id (4), scramble part 1 (8), filler (1), flags (2).
     */
    public static function greetingCapabilities(string $payload): int
    {
        $end = strpos($payload, "\0", 1);

        if ($end === false) {
            return 0;
        }

        return unpack('v', substr($payload, $end + 1 + 4 + 8 + 1, 2)."\0\0")[1] ?? 0;
    }

    /** Whether a TCP connection to the main host on $port succeeds (the control for "the TLS server is reachable"). */
    public static function reachable(string $port): bool
    {
        $socket = @fsockopen(self::env('HOST'), (int) $port, $errno, $error, 3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    // ---- internals --------------------------------------------------------------

    private function createFixtures(bool $nums): void
    {
        $admin = $this->admin();
        $items = $this->table('items');
        $sink = $this->table('sink');

        $this->track($items);
        $admin->statement("CREATE TABLE {$items} (id INT NOT NULL PRIMARY KEY, label VARCHAR(50) NOT NULL)");
        $admin->table($items)->insert([
            ['id' => 1, 'label' => 'one'],
            ['id' => 2, 'label' => 'two'],
            ['id' => 3, 'label' => 'three'],
            ['id' => 4, 'label' => 'four'],
            ['id' => 5, 'label' => 'five'],
        ]);

        $this->track($sink);
        $admin->statement("CREATE TABLE {$sink} (n BIGINT NOT NULL)");

        if ($nums) {
            $table = $this->table('nums');

            $this->track($table);
            $admin->statement("CREATE TABLE {$table} (n INT NOT NULL PRIMARY KEY)");

            foreach (array_chunk(range(1, self::NUMS_ROWS), 500) as $chunk) {
                $admin->table($table)->insert(array_map(fn (int $n): array => ['n' => $n], $chunk));
            }
        }
    }

    /** SQL Server needs its test database to exist; the other servers create it from their environment. */
    private static function ensureDatabase(): void
    {
        if (self::$databaseEnsured || self::driver() !== DbDriver::Sqlsrv) {
            return;
        }

        $database = self::env('DATABASE', 'queryproxy_test');

        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('QUERYPROXY_IT_DATABASE may only contain letters, digits and underscores.');
        }

        $pdo = new PDO(
            sprintf('sqlsrv:Server=%s,%s;Database=master;Encrypt=true;TrustServerCertificate=true', self::env('HOST'), self::env('PORT')),
            self::env('USERNAME'),
            self::env('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("IF DB_ID(N'{$database}') IS NULL CREATE DATABASE [{$database}]");

        self::$databaseEnsured = true;
    }

    /** A fresh copy of a migrated application database becomes the default connection. */
    private function useAppDatabase(): void
    {
        $this->originalDefault = DB::getDefaultConnection();

        $base = tempnam(sys_get_temp_dir(), 'qp_it_app_');
        $this->appDbFile = $base.'.sqlite';
        unlink($base);
        copy(self::appTemplate($this->originalDefault), $this->appDbFile);

        config(['database.connections.'.self::APP_CONNECTION => self::sqliteConfig($this->appDbFile)]);
        DB::purge(self::APP_CONNECTION);
        DB::setDefaultConnection(self::APP_CONNECTION);
    }

    /** A migrated application database, built once per process and copied for every test. */
    private static function appTemplate(string $originalDefault): string
    {
        if (self::$appTemplate !== null && is_file(self::$appTemplate)) {
            return self::$appTemplate;
        }

        $base = tempnam(sys_get_temp_dir(), 'qp_it_tpl_');
        $file = $base.'.sqlite';
        unlink($base);
        touch($file);

        config(['database.connections.it_app_template' => self::sqliteConfig($file)]);
        DB::setDefaultConnection('it_app_template');

        try {
            Artisan::call('migrate', ['--database' => 'it_app_template', '--force' => true]);
        } finally {
            DB::purge('it_app_template');
            DB::setDefaultConnection($originalDefault);
        }

        register_shutdown_function(static fn () => @unlink($file));

        return self::$appTemplate = $file;
    }

    /** @return array<string, mixed> */
    private static function sqliteConfig(string $file): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }

    public static function mysqlAttribute(string $name): int
    {
        foreach (['Pdo\\Mysql::ATTR_'.$name, 'PDO::MYSQL_ATTR_'.$name] as $constant) {
            if (defined($constant)) {
                return constant($constant);
            }
        }

        throw new RuntimeException('The pdo_mysql extension is required.');
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
