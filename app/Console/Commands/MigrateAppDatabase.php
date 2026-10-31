<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Moves the application database (users, teams, connections, requests, the
 * audit log, ...) to another server, typically from the default SQLite file
 * to PostgreSQL or MySQL.
 *
 * Order of work: check source and target, run the migrations on the target,
 * copy every table in foreign key order inside ONE target transaction, then
 * compare row counts and run `audit:verify` on the target.
 *
 * Audit triggers (ADR-020): the migrations install them on the target before
 * any data is copied, and the copy only INSERTs, which they allow. They are
 * never dropped. Rows keep their ids and hashes, and `audit_chain_lock` is
 * copied as it is, so the chain continues where it stopped. Because the copy
 * is a single transaction, a failure rolls back every row and leaves a
 * migrated, empty target that can be tried again; nothing has to be deleted
 * from the (append-only) audit table.
 *
 * The password of the target DSN is never printed: messages are scrubbed
 * before they reach the terminal.
 */
class MigrateAppDatabase extends Command
{
    protected $signature = 'queryproxy:migrate-appdb
        {--to= : DSN of the target database, e.g. pgsql://user:password@host:5432/queryproxy (URL-encode special characters in the password)}
        {--dry-run : Check source and target and show what would be copied, without writing anything}
        {--force : Copy without asking for confirmation (required when not interactive)}
        {--chunk=500 : Rows read from the source per round trip}';

    protected $description = 'Copy the application database (SQLite, MySQL, PostgreSQL, ...) to another server and verify the audit chain there';

    private const TARGET = 'queryproxy_migrate_target';

    /** Drivers the application database can run on. */
    private const DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    /**
     * Not copied: `migrations` is rebuilt by running the migrations on the
     * target, the cache tables only hold values that are recomputed.
     */
    private const SKIPPED_TABLES = ['migrations', 'cache', 'cache_locks'];

    /** SQL Server allows 2100 parameters per statement; stay below it everywhere. */
    private const MAX_PARAMETERS = 2000;

    /** SQL Server allows 1000 row value expressions per INSERT. */
    private const MAX_ROWS_PER_INSERT = 500;

    /** @var list<string> */
    private array $secrets = [];

    public function handle(): int
    {
        $originalDefault = DB::getDefaultConnection();

        try {
            return $this->migrateAppDatabase($originalDefault);
        } catch (QueryException $e) {
            // A QueryException message carries the SQL with its bindings, i.e.
            // row contents. Only the driver's own message is shown.
            $this->error($this->scrub($e->getPrevious()?->getMessage() ?? 'The database rejected a statement.'));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($this->scrub($e->getMessage()));

            return self::FAILURE;
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge(self::TARGET);
        }
    }

    private function migrateAppDatabase(string $sourceName): int
    {
        $dsn = trim((string) $this->option('to'));

        if ($dsn === '') {
            throw new RuntimeException('Pass the target database with --to=<dsn>, for example --to=pgsql://user:password@host:5432/queryproxy.');
        }

        $chunk = (int) $this->option('chunk');

        if ($chunk < 1 || $chunk > 10000) {
            throw new RuntimeException('--chunk must be between 1 and 10000.');
        }

        $dryRun = (bool) $this->option('dry-run');
        $config = $this->targetConfig($dsn);
        $source = DB::connection($sourceName);

        if (! in_array($source->getDriverName(), self::DRIVERS, true)) {
            throw new RuntimeException("The source database driver [{$source->getDriverName()}] is not supported as an application database.");
        }

        if ($this->isSameDatabase($source->getConfig(), $config)) {
            throw new RuntimeException('The source and the target are the same database.');
        }

        $this->assertSourceMigrated($source);

        $this->line('Source: '.$this->describe($source->getConfig()));
        $this->line('Target: '.$this->describe($config));

        $targetFileMissing = $config['driver'] === 'sqlite' && ! is_file($config['database']);

        config(['database.connections.'.self::TARGET => $config]);
        $target = DB::connection(self::TARGET);

        if (! $targetFileMissing) {
            $this->connect($target);
            $this->assertTargetEmpty($target);
        }

        $tables = $this->copyOrder($source);
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table] = $source->table($table)->count();
        }

        if ($dryRun) {
            $this->table(['Table', 'Rows to copy'], array_map(fn ($table) => [$table, $rows[$table]], $tables));
            $this->info('Dry run: the target is empty and ready; nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmed()) {
            return self::FAILURE;
        }

        // Only now: a cancelled run leaves no empty file behind.
        if ($targetFileMissing) {
            $this->createSqliteFile($config['database']);
        }

        $this->runTargetMigrations();
        $this->copyTables($source, $target, $tables, $chunk);

        return $this->verify($target, $tables, $rows);
    }

    /**
     * Turn the DSN into a connection config. Everything the DSN does not set
     * comes from the application's own config for that driver (charset, the
     * UTC session time zone MySQL needs for the audit hashes, ...), except the
     * settings the environment fills in for the *source* database.
     *
     * @return array<string, mixed>
     */
    private function targetConfig(string $dsn): array
    {
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($dsn);
        $driver = $parsed['driver'] ?? null;

        if (isset($parsed['password']) && $parsed['password'] !== '') {
            $password = (string) $parsed['password'];
            $this->secrets = array_values(array_unique([$password, rawurlencode($password), urlencode($password)]));
        }

        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException('The target DSN needs one of these drivers: '.implode(', ', self::DRIVERS).'.');
        }

        $base = array_diff_key(
            (array) config("database.connections.{$driver}"),
            array_flip(['url', 'host', 'port', 'database', 'username', 'password', 'unix_socket']),
        );

        $config = array_replace($base, $parsed);

        if ($driver === 'sqlite') {
            $path = (string) ($config['database'] ?? '');

            if ($path === '' || $path === ':memory:') {
                throw new RuntimeException('The target SQLite database must be a file; an in-memory database would vanish when the command ends.');
            }

            $config['database'] = str_starts_with($path, '/') ? $path : base_path($path);
        } elseif (empty($config['database'])) {
            throw new RuntimeException('The target DSN does not name a database (…/database_name).');
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function describe(array $config): string
    {
        if (($config['driver'] ?? '') === 'sqlite') {
            return 'sqlite ('.$config['database'].')';
        }

        return sprintf(
            '%s://%s%s%s/%s',
            $config['driver'] ?? '?',
            ! empty($config['username']) ? $config['username'].'@' : '',
            ! empty($config['host']) ? $config['host'] : 'localhost',
            ! empty($config['port']) ? ':'.$config['port'] : '',
            $config['database'] ?? '',
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function isSameDatabase(array $a, array $b): bool
    {
        if (($a['driver'] ?? null) === 'sqlite' || ($b['driver'] ?? null) === 'sqlite') {
            if (($a['driver'] ?? null) !== ($b['driver'] ?? null)) {
                return false;
            }

            $left = (string) ($a['database'] ?? '');
            $right = (string) ($b['database'] ?? '');

            return $left !== ':memory:' && $right !== ':memory:'
                && ($left === $right || (realpath($left) !== false && realpath($left) === realpath($right)));
        }

        return strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''))
            && (string) ($a['port'] ?? '') === (string) ($b['port'] ?? '')
            && (string) ($a['database'] ?? '') === (string) ($b['database'] ?? '');
    }

    /**
     * The target gets the schema from the migration files, so a source that is
     * behind them would be copied into a schema it does not match.
     */
    private function assertSourceMigrated(Connection $source): void
    {
        if (! $source->getSchemaBuilder()->hasTable('migrations')) {
            throw new RuntimeException('The source database has no migrations table; run php artisan migrate first.');
        }

        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $ran = $source->table('migrations')->pluck('migration')->all();
        $pending = array_diff($files, $ran);

        if ($pending !== []) {
            throw new RuntimeException(count($pending).' migration(s) have not run on the source database; run php artisan migrate first.');
        }
    }

    private function createSqliteFile(string $path): void
    {
        if (! is_dir(dirname($path))) {
            throw new RuntimeException('The directory of the target SQLite file does not exist: '.dirname($path));
        }

        if (@touch($path) === false) {
            throw new RuntimeException("Cannot create the target SQLite file: {$path}");
        }
    }

    private function connect(Connection $target): void
    {
        try {
            $target->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException('Cannot connect to the target database: '.$e->getMessage());
        }
    }

    /**
     * A target that was only migrated (every table empty) is fine; anything
     * else is somebody's data, and nothing is overwritten.
     */
    private function assertTargetEmpty(Connection $target): void
    {
        $names = $this->tableNames($target);

        if ($names === []) {
            return;
        }

        if (! in_array('migrations', $names, true)) {
            throw new RuntimeException("The target database is not empty: it has tables ({$names[0]}, ...) but no QueryProxy migrations table. Use an empty database.");
        }

        foreach ($names as $name) {
            if (in_array($name, self::SKIPPED_TABLES, true)) {
                continue;
            }

            // The migrations seed the chain lock row, so count what it recorded.
            $count = $name === AuditChain::LOCK_TABLE
                ? (int) $target->table($name)->sum('sequence')
                : $target->table($name)->count();

            if ($count > 0) {
                throw new RuntimeException("The target database is not empty: table {$name} holds {$count} row(s). If an application already started against it, use a fresh database.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tableNames(Connection $connection): array
    {
        // Only the schemas this connection works in: on MySQL/MariaDB a null
        // filter returns the tables of EVERY database the login can see.
        $builder = $connection->getSchemaBuilder();
        $schema = $builder->getCurrentSchemaListing();

        // SQL Server's builder has no schema list; its default schema (dbo) is
        // what the application's tables live in.
        if ($schema === null && $connection->getDriverName() === 'sqlsrv') {
            $schema = [$builder->getCurrentSchemaName()];
        }

        $names = array_column($builder->getTables($schema), 'name');
        sort($names);

        return array_values($names);
    }

    /**
     * The tables to copy, parents before children.
     *
     * @return list<string>
     */
    private function copyOrder(Connection $source): array
    {
        $tables = array_values(array_diff($this->tableNames($source), self::SKIPPED_TABLES));
        $schema = $source->getSchemaBuilder();
        $depends = [];

        foreach ($tables as $table) {
            $parents = array_column($schema->getForeignKeys($table), 'foreign_table');
            $depends[$table] = array_values(array_unique(array_filter(
                $parents,
                fn ($parent) => $parent !== $table && in_array($parent, $tables, true),
            )));
        }

        $ordered = [];

        while ($depends !== []) {
            $ready = array_keys(array_filter($depends, fn (array $parents) => array_diff($parents, $ordered) === []));

            if ($ready === []) {
                throw new RuntimeException('The foreign keys of these tables depend on each other: '.implode(', ', array_keys($depends)).'.');
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($depends[$table]);
            }
        }

        return $ordered;
    }

    private function confirmed(): bool
    {
        $this->warn('Stop QueryProxy (web, queue worker and scheduler) before copying: changes made while the copy runs are not carried over.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Confirmation is required; pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Has the application been stopped, and do you want to copy the data now?', false)) {
            $this->line('Cancelled; nothing was written.');

            return false;
        }

        return true;
    }

    private function runTargetMigrations(): void
    {
        $this->line('Running the migrations on the target...');

        $output = new BufferedOutput;
        $status = Artisan::call('migrate', ['--database' => self::TARGET, '--force' => true], $output);

        if ($status !== 0) {
            throw new RuntimeException("The migrations failed on the target:\n".trim($output->fetch()));
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function copyTables(Connection $source, Connection $target, array $tables, int $chunk): void
    {
        try {
            $target->transaction(function () use ($source, $target, $tables, $chunk) {
                foreach ($tables as $table) {
                    $copied = $this->copyTable($source, $target, $table, $chunk);
                    $this->line("  {$table}: {$copied} row(s)");
                }

                $this->resetSequences($target, $tables);
            });
        } catch (Throwable $e) {
            $this->error('The copy failed and was rolled back: no row was written to the target tables. After fixing the cause, run the command again (the target is migrated and still empty).');

            throw $e;
        }
    }

    private function copyTable(Connection $source, Connection $target, string $table, int $chunk): int
    {
        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        $columns = $sourceSchema->getColumnListing($table);
        $missing = array_diff($columns, $targetSchema->getColumnListing($table));

        if ($missing !== []) {
            throw new RuntimeException("Table {$table} on the target lacks the column(s) ".implode(', ', $missing).'.');
        }

        $primary = $this->primaryKey($sourceSchema, $table);
        $booleans = $target->getDriverName() === 'pgsql' ? $this->booleanColumns($targetSchema, $table) : [];
        $identity = $this->identityColumn($targetSchema, $table);
        $perInsert = max(1, min(self::MAX_ROWS_PER_INSERT, intdiv(self::MAX_PARAMETERS, count($columns))));
        $wrappedTable = $target->getQueryGrammar()->wrapTable($table);

        // Seeded by the migration; the source's row replaces it.
        if ($table === AuditChain::LOCK_TABLE) {
            $target->table($table)->delete();
        }

        // SQL Server refuses explicit values for an IDENTITY column unless told.
        $identityInsert = $identity !== null && $target->getDriverName() === 'sqlsrv';

        if ($identityInsert) {
            $target->unprepared("SET IDENTITY_INSERT {$wrappedTable} ON");
        }

        $copied = 0;
        $last = null;

        try {
            while (true) {
                $query = $source->table($table)->orderBy($primary)->limit($chunk);

                if ($last !== null) {
                    $query->where($primary, '>', $last);
                }

                $rows = $query->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $records = $rows->map(function ($row) use ($booleans) {
                    $record = (array) $row;

                    foreach ($booleans as $column) {
                        if (isset($record[$column])) {
                            $record[$column] = (bool) $record[$column];
                        }
                    }

                    return $record;
                })->all();

                foreach (array_chunk($records, $perInsert) as $batch) {
                    $target->table($table)->insert($batch);
                }

                $last = $rows->last()->{$primary};
                $copied += $rows->count();
            }
        } finally {
            if ($identityInsert) {
                try {
                    $target->unprepared("SET IDENTITY_INSERT {$wrappedTable} OFF");
                } catch (Throwable) {
                    // A failed statement may already have ended the session's
                    // transaction; the original error is the one to report.
                }
            }
        }

        return $copied;
    }

    private function primaryKey(SchemaBuilder $schema, string $table): string
    {
        foreach ($schema->getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        throw new RuntimeException("Table {$table} has no single-column primary key; it cannot be copied in chunks.");
    }

    private function identityColumn(SchemaBuilder $schema, string $table): ?string
    {
        foreach ($schema->getColumns($table) as $column) {
            if ($column['auto_increment'] ?? false) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function booleanColumns(SchemaBuilder $schema, string $table): array
    {
        $names = [];

        foreach ($schema->getColumns($table) as $column) {
            if (in_array($column['type_name'], ['bool', 'boolean'], true)) {
                $names[] = $column['name'];
            }
        }

        return $names;
    }

    /**
     * Rows were inserted with their ids. PostgreSQL's sequences do not follow
     * explicit ids, so the next insert would collide; MySQL, SQLite and SQL
     * Server move their counters by themselves.
     *
     * @param  list<string>  $tables
     */
    private function resetSequences(Connection $target, array $tables): void
    {
        if ($target->getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = $target->getQueryGrammar();

        foreach ($tables as $table) {
            $column = $this->identityColumn($target->getSchemaBuilder(), $table);

            if ($column === null) {
                continue;
            }

            $wrappedTable = $grammar->wrapTable($table);
            $wrappedColumn = $grammar->wrap($column);

            $target->selectOne(
                "select setval(pg_get_serial_sequence(?, ?), coalesce(max({$wrappedColumn}), 1), max({$wrappedColumn}) is not null) as value from {$wrappedTable}",
                [$wrappedTable, $column],
            );
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, int>  $expected  row counts read from the source before the copy
     */
    private function verify(Connection $target, array $tables, array $expected): int
    {
        $report = [];
        $matches = true;

        foreach ($tables as $table) {
            $copied = $target->table($table)->count();
            $same = $copied === $expected[$table];
            $matches = $matches && $same;
            $report[] = [$table, $expected[$table], $copied, $same ? 'ok' : 'MISMATCH'];
        }

        $this->table(['Table', 'Source', 'Target', 'Result'], $report);

        if (! $matches) {
            $this->error('Row counts differ between source and target. The target was NOT verified; do not switch the application to it.');

            return self::FAILURE;
        }

        // audit:verify reads through the default connection.
        DB::setDefaultConnection(self::TARGET);

        $output = new BufferedOutput;
        $status = Artisan::call('audit:verify', [], $output);

        $this->line(trim($output->fetch()));

        if ($status !== 0) {
            $this->error('The audit chain on the target does not verify. Do not switch the application to it.');

            return self::FAILURE;
        }

        $this->info('Done. Point QueryProxy at the target (DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD) and start it again.');

        return self::SUCCESS;
    }

    /**
     * Remove the target password (as given and URL-encoded) from a message.
     */
    private function scrub(string $message): string
    {
        return $this->secrets === [] ? $message : str_replace($this->secrets, '***', $message);
    }
}
