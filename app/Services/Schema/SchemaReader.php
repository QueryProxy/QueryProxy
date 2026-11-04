<?php

namespace App\Services\Schema;

use App\Enums\AuditEvent;
use App\Enums\DbDriver;
use App\Enums\MaskMatchType;
use App\Jobs\Middleware\LimitConnectionConcurrency;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\User;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Execution\TargetSession;
use App\Services\Masking\Masker;
use App\Services\Schema\Catalog\Catalogs;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the table and column names of a target database for the Studio's
 * schema tree and completion (ADR-029, decision D-16 b).
 *
 * This is a fixed code path, not a query run: the catalog queries are constants
 * of the code (see Catalog), no user input reaches them, and user-written SQL
 * never takes this path. It therefore needs no approval, and each live read is
 * audited as `schema.read`. It uses the same hardened session as an approved
 * read (read identity where the connection has one, READ ONLY session, statement
 * timeout of its own) and takes a connection slot like a query run does.
 *
 * Only names and types are read, never data. The cache holds exactly that; the
 * `masked` flag of a column is worked out from the masking rules on every
 * answer, so a rule change shows at once. Only column-name rules can be judged
 * from a name: content rules (patterns, validators) act on values.
 */
class SchemaReader
{
    /** Default for `queryproxy.schema_max_columns`: most columns one read returns. */
    public const MAX_COLUMNS = 20000;

    public function __construct(
        private DynamicConnectionFactory $factory,
        private TargetSession $session,
        private Masker $masker,
    ) {}

    /**
     * @param  bool  $refresh  skip the cache and read the target again
     * @return array{connection_id: int, driver: string, source: string, fetched_at: string, default_schema: string|null, truncated: bool, schemas: list<array<string, mixed>>}
     *
     * @throws SchemaBusyException when every connection slot is taken
     * @throws SchemaTargetException when the target cannot be read
     */
    public function read(Connection $connection, User $actor, bool $refresh = false): array
    {
        $ttl = (int) config('queryproxy.schema_cache_ttl', 3600);
        $key = $this->cacheKey($connection);
        $source = 'cache';
        $tree = null;

        if (! $refresh && $ttl > 0) {
            $cached = Cache::get($key);
            $tree = is_array($cached) && isset($cached['schemas'], $cached['fetched_at']) ? $cached : null;
        }

        if ($tree === null) {
            $source = 'live';
            $tree = $this->readLive($connection);

            // Audited before it is cached or returned: a read that cannot be
            // recorded is not served. The application's own failure is kept
            // apart from the target's, so its SQL never reaches an answer.
            audit()->record(AuditEvent::SchemaRead, actor: $actor, connection: $connection, metadata: [
                'source' => 'live',
                'objects' => $this->countTables($tree['schemas']),
                'columns' => $this->countColumns($tree['schemas']),
                'refreshed' => $refresh,
            ] + ($tree['truncated'] ? ['truncated' => true] : []));

            if ($ttl > 0) {
                Cache::put($key, $tree, $ttl);
            }
        }

        return [
            'connection_id' => $connection->id,
            'driver' => $connection->driver->value,
            'source' => $source,
            'fetched_at' => $tree['fetched_at'],
            'default_schema' => $tree['default_schema'],
            'truncated' => $tree['truncated'],
            'schemas' => $this->flagMasked($tree['schemas'], $this->masker->rulesFor($connection)),
        ];
    }

    /**
     * Cache key of the connection. It carries the connection's last change, so
     * editing the connection (host, database, credentials) starts a fresh entry.
     */
    public function cacheKey(Connection $connection): string
    {
        return sprintf('queryproxy:schema:%d:%d', $connection->id, $connection->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * @return array{fetched_at: string, default_schema: string|null, truncated: bool, schemas: list<array<string, mixed>>}
     */
    private function readLive(Connection $connection): array
    {
        $timeout = max(1, (int) config('queryproxy.schema_timeout', 5));
        $max = max(1, (int) config('queryproxy.schema_max_columns', self::MAX_COLUMNS));
        $slot = $this->takeSlot($connection, $timeout);
        $driver = $connection->driver;
        $catalog = Catalogs::for($driver);

        try {
            $name = $connection->hasReadCredentials() && $driver !== DbDriver::Sqlite
                ? $this->factory->configureRead($connection)
                : $this->factory->configure($connection);

            $db = DB::connection($name);
            $opened = false;

            try {
                $opened = true;
                $this->session->beginReadOnly($db, $driver, $timeout);

                $rows = $catalog->fetch($db, $max + 1);
                $default = $this->defaultSchema($db, $catalog->defaultSchemaSql());

                $this->session->endReadOnly($db, commit: true);
                $opened = false;
            } catch (Throwable $e) {
                if ($opened) {
                    $this->session->endReadOnly($db, commit: false);
                }

                throw $e;
            }
        } catch (Throwable $e) {
            throw new SchemaTargetException($e);
        } finally {
            $this->factory->purge($connection);
            $slot?->release();
        }

        return [
            'fetched_at' => now()->toIso8601String(),
            'default_schema' => $default,
        ] + SchemaTree::fromRows($rows, $max);
    }

    /** Best effort: the tree is useful without it. */
    private function defaultSchema(\Illuminate\Database\Connection $db, ?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        try {
            $value = $db->scalar($sql);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Same slot rule as a query run (LimitConnectionConcurrency): the read
     * takes one of the connection's slots, or is refused when all are busy.
     *
     * @throws SchemaBusyException
     */
    private function takeSlot(Connection $connection, int $timeout): ?Lock
    {
        $slots = (int) config('queryproxy.connection_slots', 2);

        if ($slots < 1) {
            return null;
        }

        $ttl = $timeout + LimitConnectionConcurrency::LOCK_MARGIN;

        for ($slot = 1; $slot <= $slots; $slot++) {
            $lock = Cache::lock(LimitConnectionConcurrency::lockName($connection->id, $slot), $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new SchemaBusyException(max(1, (int) config('queryproxy.connection_slot_retry_delay', 5)));
    }

    /**
     * @param  list<array<string, mixed>>  $schemas
     * @param  Collection<int, MaskingRule>  $rules
     * @return list<array<string, mixed>>
     */
    private function flagMasked(array $schemas, Collection $rules): array
    {
        $patterns = $rules
            ->filter(fn ($rule) => $rule->match_type === MaskMatchType::Column)
            ->map(fn ($rule) => (string) $rule->pattern)
            ->values();

        foreach ($schemas as $s => $schema) {
            foreach ($schema['tables'] as $t => $table) {
                foreach ($table['columns'] as $c => $column) {
                    $schemas[$s]['tables'][$t]['columns'][$c]['masked'] = $patterns->contains(
                        fn (string $pattern) => $this->masker->columnMatches($pattern, $column['name'])
                    );
                }
            }
        }

        return $schemas;
    }

    /** @param  list<array<string, mixed>>  $schemas */
    private function countTables(array $schemas): int
    {
        return array_sum(array_map(fn (array $schema) => count($schema['tables']), $schemas));
    }

    /** @param  list<array<string, mixed>>  $schemas */
    private function countColumns(array $schemas): int
    {
        $count = 0;

        foreach ($schemas as $schema) {
            foreach ($schema['tables'] as $table) {
                $count += count($table['columns']);
            }
        }

        return $count;
    }
}
