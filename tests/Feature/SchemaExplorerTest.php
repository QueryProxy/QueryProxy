<?php

use App\Enums\DbDriver;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Execution\TargetSession;
use App\Services\Schema\Catalog\Catalogs;
use App\Services\Schema\SchemaReader;
use App\Services\Schema\SchemaTree;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The Studio's schema tree (ADR-029): GET /connections/{id}/schema reads the
 * catalog of the target database through a fixed code path, with no approval.
 */
function schemaSetup(TeamRole $role = TeamRole::Developer, bool $granted = true): array
{
    $dbFile = tempnam(sys_get_temp_dir(), 'qp_schema_');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email VARCHAR(120), balance NUMERIC)');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL)');
    $pdo->exec('CREATE VIEW active_customers AS SELECT id, name FROM customers');
    unset($pdo);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => $role->value]);
    $connection = Connection::factory()->sqlite($dbFile)->create(['team_id' => $team->id]);

    if ($granted) {
        $connection->grantedUsers()->attach($user);
    }

    // New teams start with the default masking rules; this test sets its own.
    $team->maskingRules()->delete();
    session(['current_team_id' => $team->id]);
    Cache::flush();

    return [$user, $connection, $team, $dbFile];
}

/** Count the statements that reach the target database of $connection. */
function countTargetStatements(Connection $connection): object
{
    $counter = new stdClass;
    $counter->count = 0;
    $name = 'queryproxy_target_'.$connection->id;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($counter, $name) {
        if ($query->connectionName === $name) {
            $counter->count++;
        }
    });

    return $counter;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/qp_schema_*') ?: [] as $file) {
        @unlink($file);
    }
});

test('a user with a grant gets the table and column tree', function () {
    [$user, $connection] = schemaSetup();

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertJsonPath('data.connection_id', $connection->id)
        ->assertJsonPath('data.driver', 'sqlite')
        ->assertJsonPath('data.source', 'live')
        ->assertJsonPath('data.truncated', false);

    $schemas = $response->json('data.schemas');

    expect($schemas)->toHaveCount(1)
        ->and($schemas[0]['name'])->toBe('main')
        ->and(array_column($schemas[0]['tables'], 'name'))->toBe(['active_customers', 'customers', 'orders'])
        ->and(array_column($schemas[0]['tables'], 'type'))->toBe(['view', 'table', 'table']);

    $customers = collect($schemas[0]['tables'])->firstWhere('name', 'customers');

    expect($customers['columns'])->toBe([
        ['name' => 'id', 'type' => 'INTEGER', 'nullable' => true, 'masked' => false],
        ['name' => 'name', 'type' => 'TEXT', 'nullable' => false, 'masked' => false],
        ['name' => 'email', 'type' => 'VARCHAR(120)', 'nullable' => true, 'masked' => false],
        ['name' => 'balance', 'type' => 'NUMERIC', 'nullable' => true, 'masked' => false],
    ]);
});

test('a DBA of the team needs no grant', function () {
    [$user, $connection] = schemaSetup(TeamRole::Dba, granted: false);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();
});

test('a developer without a grant gets 403 and nothing is read or audited', function () {
    [$user, $connection] = schemaSetup(granted: false);
    $statements = countTargetStatements($connection);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertForbidden();

    expect($statements->count)->toBe(0)
        ->and(AuditLog::where('action', 'schema.read')->count())->toBe(0);
});

test('an auditor gets 403 even though the policy lets auditors view the connection', function () {
    [$user, $connection] = schemaSetup(TeamRole::Auditor, granted: false);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertForbidden();
});

test('an expired or revoked grant gets 403', function () {
    [$user, $connection] = schemaSetup();
    $connection->grantedUsers()->detach($user);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertForbidden();
});

test('a connection of another team is not found', function () {
    [$user] = schemaSetup();
    $other = Connection::factory()->create(['team_id' => Team::factory()->create()->id]);

    $this->actingAs($user)->getJson("/connections/{$other->id}/schema")->assertNotFound();
});

test('a guest is sent to sign in', function () {
    [, $connection] = schemaSetup();

    $this->getJson("/connections/{$connection->id}/schema")->assertUnauthorized();
});

test('columns matched by a column masking rule are flagged as masked', function () {
    [$user, $connection, $team] = schemaSetup();
    MaskingRule::factory()->create(['team_id' => $team->id, 'connection_id' => null, 'pattern' => 'email|balance']);
    // A rule of another team, and a disabled one, must not count.
    MaskingRule::factory()->create(['team_id' => Team::factory()->create()->id, 'connection_id' => null, 'pattern' => 'name']);
    MaskingRule::factory()->create(['team_id' => $team->id, 'connection_id' => null, 'pattern' => 'customer_id', 'enabled' => false]);

    $tables = collect($this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->json('data.schemas.0.tables'));

    $masked = fn (string $table) => $tables->firstWhere('name', $table)['columns'];

    expect(collect($masked('customers'))->where('masked', true)->pluck('name')->all())->toBe(['email', 'balance'])
        ->and(collect($masked('orders'))->where('masked', true))->toBeEmpty();
});

test('a masking rule change shows at once even when the schema comes from the cache', function () {
    [$user, $connection, $team] = schemaSetup();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();
    MaskingRule::factory()->create(['team_id' => $team->id, 'connection_id' => null, 'pattern' => 'email']);

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")
        ->assertOk()
        ->assertJsonPath('data.source', 'cache');

    $email = collect($response->json('data.schemas.0.tables'))->firstWhere('name', 'customers')['columns'][2];

    expect($email['name'])->toBe('email')->and($email['masked'])->toBeTrue();
});

test('a live read writes one schema.read audit entry', function () {
    [$user, $connection] = schemaSetup();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();

    $entry = AuditLog::where('action', 'schema.read')->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->connection_id)->toBe($connection->id)
        ->and($entry->team_id)->toBe($connection->team_id)
        ->and($entry->sql)->toBeNull()
        ->and($entry->metadata)->toBe(['source' => 'live', 'objects' => 3, 'columns' => 8, 'refreshed' => false]);
});

test('a second request inside the TTL is served from the cache: no database statement, no audit entry', function () {
    [$user, $connection] = schemaSetup();
    $statements = countTargetStatements($connection);

    $first = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');
    $afterFirst = $statements->count;

    $second = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'cache');

    expect($afterFirst)->toBeGreaterThan(0)
        ->and($statements->count)->toBe($afterFirst)
        ->and($second->json('data.schemas'))->toBe($first->json('data.schemas'))
        ->and($second->json('data.fetched_at'))->toBe($first->json('data.fetched_at'))
        ->and(AuditLog::where('action', 'schema.read')->count())->toBe(1);
});

test('the cache is kept per connection', function () {
    [$user, $connection, $team] = schemaSetup();
    $second = Connection::factory()->sqlite(tempnam(sys_get_temp_dir(), 'qp_schema_'))->create(['team_id' => $team->id]);
    $second->grantedUsers()->attach($user);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');
    $this->actingAs($user)->getJson("/connections/{$second->id}/schema")->assertOk()->assertJsonPath('data.source', 'live')
        ->assertJsonPath('data.schemas', []);
});

test('the cache expires after the TTL', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.schema_cache_ttl' => 60]);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');

    $this->travel(61)->seconds();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');

    expect(AuditLog::where('action', 'schema.read')->count())->toBe(2);
});

test('a TTL of 0 turns the cache off', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.schema_cache_ttl' => 0]);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();
    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');
});

test('editing the connection starts a fresh cache entry', function () {
    [$user, $connection] = schemaSetup();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();

    $this->travel(5)->seconds();
    $connection->update(['name' => 'Renamed']);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'live');
});

test('refresh skips the cache, is audited and is rate limited', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.schema_refresh_per_minute' => 2]);
    RateLimiter::clear("schema-refresh:{$user->id}:{$connection->id}");

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema?refresh=1")->assertOk()->assertJsonPath('data.source', 'live');
    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema?refresh=1")->assertOk()->assertJsonPath('data.source', 'live');
    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema?refresh=1")
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // A plain read is not a refresh and still comes from the cache.
    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk()->assertJsonPath('data.source', 'cache');

    expect(AuditLog::where('action', 'schema.read')->count())->toBe(3)
        ->and(AuditLog::where('action', 'schema.read')->latest('id')->first()->metadata['refreshed'])->toBeTrue();
});

test('the read takes a connection slot and is refused with 503 when every slot is busy', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.connection_slots' => 1, 'queryproxy.connection_slot_retry_delay' => 9]);
    $busy = Cache::lock("conn:{$connection->id}:slot:1", 60);
    expect($busy->get())->toBeTrue();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")
        ->assertStatus(503)
        ->assertHeader('Retry-After', '9');

    expect(AuditLog::where('action', 'schema.read')->count())->toBe(0);

    $busy->release();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();

    // The slot is free again once the read is over.
    $again = Cache::lock("conn:{$connection->id}:slot:1", 5);
    expect($again->get())->toBeTrue();
});

test('the slot is released when the read fails', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.connection_slots' => 1]);
    $connection->forceFill(['database' => '/nonexistent-dir/none.sqlite'])->save();

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertStatus(502);

    $lock = Cache::lock("conn:{$connection->id}:slot:1", 5);
    expect($lock->get())->toBeTrue();
});

test('a failed read returns 502 with a message that does not leak the connection and writes no audit entry', function () {
    [$user, $connection] = schemaSetup();
    $connection->forceFill(['database' => '/nonexistent-dir/none.sqlite'])->save();

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertStatus(502);

    expect($response->json('message'))->toStartWith('The schema could not be read')
        ->and($response->json('message'))->not->toContain('/nonexistent-dir')
        ->and(AuditLog::where('action', 'schema.read')->count())->toBe(0);
});

test('the read runs in a read-only session with the schema timeout', function () {
    [, $connection] = schemaSetup();
    $user = User::factory()->create();
    config(['queryproxy.schema_timeout' => 3]);

    $seen = [];
    $session = Mockery::mock(TargetSession::class)->makePartial();
    $session->shouldReceive('beginReadOnly')->once()->andReturnUsing(function ($db, $driver, $seconds) use (&$seen) {
        $seen = ['driver' => $driver, 'seconds' => $seconds];

        // What the real method does on SQLite, so the read runs as it would.
        $db->unprepared('PRAGMA query_only = ON');
    });
    app()->instance(TargetSession::class, $session);

    app(SchemaReader::class)->read($connection, $user);

    expect($seen['driver'])->toBe(DbDriver::Sqlite)->and($seen['seconds'])->toBe(3);
});

test('the limit statements of a session with its own timeout use it', function () {
    config(['queryproxy.execution_timeout' => 300, 'queryproxy.lock_timeout' => 5]);
    $session = new TargetSession;

    expect($session->limitStatements(DbDriver::Pgsql, true, 4))->toBe([
        'SET LOCAL statement_timeout = 4000',
        'SET LOCAL lock_timeout = 5000',
        'SET LOCAL idle_in_transaction_session_timeout = 4000',
    ])
        ->and($session->limitStatements(DbDriver::Mysql, false, 4)[0])->toBe('SET SESSION MAX_EXECUTION_TIME = 4000')
        ->and($session->limitStatements(DbDriver::Mariadb, false, 4)[0])->toBe('SET SESSION max_statement_time = 4')
        // Without an override nothing changed: the derived timeout, idle = execution timeout.
        ->and($session->limitStatements(DbDriver::Pgsql, true)[0])->toBe('SET LOCAL statement_timeout = 295000')
        ->and($session->limitStatements(DbDriver::Pgsql, true)[2])->toBe('SET LOCAL idle_in_transaction_session_timeout = 300000');
});

test('the schema endpoint takes no SQL: request input never reaches the catalog query', function () {
    [$user, $connection] = schemaSetup();
    $queries = [];
    $name = 'queryproxy_target_'.$connection->id;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries, $name) {
        if ($query->connectionName === $name) {
            $queries[] = [$query->sql, $query->bindings];
        }
    });

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema?sql=drop+table+customers&schema=x'--&refresh=0")->assertOk();

    expect(collect($queries)->contains(fn ($q) => str_contains($q[0], 'drop') || str_contains(json_encode($q[1]), 'drop')))->toBeFalse();
});

test('every catalog query is a constant statement without placeholders', function (DbDriver $driver) {
    $sql = Catalogs::for($driver)->sql(10);

    expect($sql)->not->toContain('?')->and($sql)->not->toContain(':')->and($sql)->toContain('10');
})->with([
    DbDriver::Pgsql, DbDriver::Mysql, DbDriver::Mariadb,
    DbDriver::Sqlsrv, DbDriver::Sqlite,
]);

// ---- review round 1 -----------------------------------------------------------

/** @return array<string, mixed> */
function catalogRow(string $schema, string $table, string $column): array
{
    return ['schema_name' => $schema, 'table_name' => $table, 'table_type' => 'table', 'column_name' => $column, 'data_type' => 'int', 'is_nullable' => 'NO'];
}

test('the pgsql catalog lists the default schema of the session first, so a cut never loses it', function () {
    expect(Catalogs::for(DbDriver::Pgsql)->sql(10))->toContain('order by case when c.table_schema = current_schema() then 0 else 1 end, ');
});

test('the MySQL and MariaDB catalog is limited to the connection\'s own database', function (DbDriver $driver) {
    $sql = Catalogs::for($driver)->sql(10);

    expect($sql)->toContain('where c.table_schema = database()')
        // The system schemas stay out as well.
        ->and($sql)->toContain("not in ('information_schema', 'mysql', 'performance_schema', 'sys')")
        ->and($sql)->toContain('order by c.table_name, c.ordinal_position');
})->with([DbDriver::Mysql, DbDriver::Mariadb]);

test('the SQL Server catalog orders the default schema first and names the INFORMATION_SCHEMA views in capitals', function () {
    $sql = Catalogs::for(DbDriver::Sqlsrv)->sql(10);

    expect($sql)->toContain('order by case when c.TABLE_SCHEMA = schema_name() then 0 else 1 end, ')
        // A case-sensitive collation does not find lower-case view or column names.
        ->and($sql)->toContain('INFORMATION_SCHEMA.COLUMNS')->toContain('INFORMATION_SCHEMA.TABLES')
        ->and($sql)->not->toContain('information_schema')
        ->and($sql)->not->toMatch('/\bc\.[a-z_]+\b/');
});

test('a cut listing keeps the schema it starts with and drops the table the cut went through', function () {
    // Default schema "shop" first, as the catalogs order it; the cap of 5 falls inside "orders".
    $rows = [
        catalogRow('shop', 'customers', 'id'), catalogRow('shop', 'customers', 'name'),
        catalogRow('shop', 'orders', 'id'), catalogRow('shop', 'orders', 'total'), catalogRow('shop', 'orders', 'placed_at'),
        catalogRow('shop', 'orders', 'status'),
        catalogRow('tenant_a', 'users', 'id'),
    ];

    $tree = SchemaTree::fromRows(array_slice($rows, 0, 6), 5);

    expect($tree['truncated'])->toBeTrue()
        ->and(array_column($tree['schemas'], 'name'))->toBe(['shop'])
        ->and(array_column($tree['schemas'][0]['tables'], 'name'))->toBe(['customers']);
});

test('a cut that falls between two tables keeps every table whole', function () {
    $rows = [
        catalogRow('shop', 'customers', 'id'), catalogRow('shop', 'customers', 'name'),
        catalogRow('shop', 'orders', 'id'),
        catalogRow('tenant_a', 'users', 'id'),
    ];

    $tree = SchemaTree::fromRows($rows, 3);

    expect($tree['truncated'])->toBeTrue()
        ->and(array_column($tree['schemas'][0]['tables'], 'name'))->toBe(['customers', 'orders'])
        ->and(array_map(fn ($t) => count($t['columns']), $tree['schemas'][0]['tables']))->toBe([2, 1]);
});

test('a listing of exactly the cap is not cut', function () {
    $rows = [catalogRow('shop', 'a', 'id'), catalogRow('shop', 'a', 'x'), catalogRow('shop', 'b', 'id')];

    expect(SchemaTree::fromRows($rows, 3)['truncated'])->toBeFalse()
        ->and(SchemaTree::fromRows($rows, 2)['truncated'])->toBeTrue();
});

test('a single table larger than the cap is kept cut rather than dropped to an empty tree', function () {
    $rows = [catalogRow('shop', 'wide', 'a'), catalogRow('shop', 'wide', 'b'), catalogRow('shop', 'wide', 'c')];

    $tree = SchemaTree::fromRows($rows, 2);

    expect($tree['truncated'])->toBeTrue()
        ->and($tree['schemas'][0]['tables'][0]['columns'])->toHaveCount(2);
});

test('the size cap cuts the endpoint listing at a table boundary and is audited', function () {
    [$user, $connection] = schemaSetup();
    // Tables by name: active_customers (2 columns), customers (4), orders (2).
    config(['queryproxy.schema_max_columns' => 5]);

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")
        ->assertOk()
        ->assertJsonPath('data.truncated', true);

    expect(array_column($response->json('data.schemas.0.tables'), 'name'))->toBe(['active_customers'])
        ->and(AuditLog::where('action', 'schema.read')->sole()->metadata)
        ->toBe(['source' => 'live', 'objects' => 1, 'columns' => 2, 'refreshed' => false, 'truncated' => true]);

    // The cut answer is what the cache serves too.
    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertJsonPath('data.truncated', true)->assertJsonPath('data.source', 'cache');
});

test('a cap that holds the whole schema leaves it whole', function () {
    [$user, $connection] = schemaSetup();
    config(['queryproxy.schema_max_columns' => 8]);

    $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")
        ->assertOk()
        ->assertJsonPath('data.truncated', false)
        ->assertJsonCount(3, 'data.schemas.0.tables');
});

test('a view whose table was dropped does not stop the other objects from being listed', function () {
    [$user, $connection, , $dbFile] = schemaSetup();
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('DROP VIEW active_customers');
    $pdo->exec('CREATE VIEW broken AS SELECT id FROM customers');
    $pdo->exec('DROP TABLE customers');
    unset($pdo);

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertOk();

    expect(array_column($response->json('data.schemas.0.tables'), 'name'))->toBe(['orders']);
});

test('a failing audit write answers 500 with a fixed message, serves and caches nothing', function () {
    [$user, $connection] = schemaSetup();
    $secret = 'insert into "audit_logs" ("hash") values (\'s3cr3t-chain-hash\')';

    $recorder = Mockery::mock(AuditRecorder::class);
    $recorder->shouldReceive('record')->andThrow(new RuntimeException($secret));
    app()->instance(AuditRecorder::class, $recorder);

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertStatus(500);

    expect($response->json('message'))->toBe('The schema could not be read.')
        ->and($response->getContent())->not->toContain('audit_logs')->not->toContain('s3cr3t')
        ->and(Cache::has(app(SchemaReader::class)->cacheKey($connection)))->toBeFalse();
});

test('a failing cache does not put its SQL into the answer either', function () {
    [$user, $connection] = schemaSetup();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('select * from "cache" where "key" = \'x\''));

    $response = $this->actingAs($user)->getJson("/connections/{$connection->id}/schema")->assertStatus(500);

    expect($response->getContent())->not->toContain('select *');
});
