<?php

/*
 * The fixed catalog queries of the schema explorer (ADR-029) on a real server:
 * SchemaReader::read() lists the tables, views and columns of the target for
 * PostgreSQL (and the systems that speak its catalog), MySQL, MariaDB and SQL
 * Server. The SQLite catalog is covered by tests/Feature/SchemaExplorerTest.php.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Models\AuditLog;
use App\Models\MaskingRule;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Schema\SchemaReader;
use Illuminate\Support\Facades\Cache;
use Tests\Integration\IntegrationTarget;

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    $this->it = IntegrationTarget::start();
    $this->view = $this->it->table('view');
    Cache::flush();
});

afterEach(function () {
    if (isset($this->it)) {
        // The view depends on the items table: it has to go first.
        $this->it->admin()->statement("DROP VIEW IF EXISTS {$this->view}");
        app(DynamicConnectionFactory::class)->purge($this->it->connection());
        $this->it->stop();
    }
});

/** Table named $name in the tree, whatever schema it is in. */
function itSchemaTable(array $tree, string $name): ?array
{
    foreach ($tree['schemas'] as $schema) {
        foreach ($schema['tables'] as $table) {
            if ($table['name'] === $name) {
                return $table + ['schema' => $schema['name']];
            }
        }
    }

    return null;
}

test('the catalog lists the tables, the view and the columns with type and nullability', function () {
    $items = $this->it->table('items');
    $this->it->admin()->statement("CREATE VIEW {$this->view} AS SELECT id, label FROM {$items}");

    $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);

    $table = itSchemaTable($tree, $items);
    $view = itSchemaTable($tree, $this->view);

    expect($tree['source'])->toBe('live')
        ->and($tree['truncated'])->toBeFalse()
        ->and($table)->not->toBeNull()
        ->and($table['type'])->toBe('table')
        ->and(array_column($table['columns'], 'name'))->toBe(['id', 'label'])
        ->and(array_column($table['columns'], 'nullable'))->toBe([false, false])
        ->and(strtolower($table['columns'][0]['type']))->toContain('int')
        ->and(strtolower($table['columns'][1]['type']))->toContain('char')
        ->and($view)->not->toBeNull()
        ->and($view['type'])->toBe('view')
        ->and(array_column($view['columns'], 'name'))->toBe(['id', 'label']);
});

test('the system schemas are left out', function () {
    $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);
    $names = array_map('strtolower', array_column($tree['schemas'], 'name'));

    expect(array_intersect($names, ['information_schema', 'pg_catalog', 'crdb_internal', 'mysql', 'performance_schema', 'sys']))->toBe([])
        ->and(array_filter($names, fn (string $name) => str_starts_with($name, 'pg_')))->toBe([]);
});

test('columns are listed in column order, not by name', function () {
    $wide = $this->it->table('wide');
    $this->it->track($wide);
    $this->it->admin()->statement("CREATE TABLE {$wide} (c_b INT NOT NULL, c_a VARCHAR(10) NULL, c_c INT NULL)");

    $table = itSchemaTable(app(SchemaReader::class)->read($this->it->connection(), $this->it->owner), $wide);

    expect(array_column($table['columns'], 'name'))->toBe(['c_b', 'c_a', 'c_c'])
        ->and(array_column($table['columns'], 'nullable'))->toBe([false, true, true]);
});

test('a column matched by a column masking rule is flagged', function () {
    MaskingRule::factory()->create(['team_id' => $this->it->team->id, 'connection_id' => null, 'pattern' => 'label']);

    $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);
    $columns = itSchemaTable($tree, $this->it->table('items'))['columns'];

    expect(array_column($columns, 'masked'))->toBe([false, true]);
});

test('the read has a default schema and is audited once', function () {
    $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);

    expect($tree['default_schema'])->not->toBeNull()
        ->and(AuditLog::where('action', 'schema.read')->count())->toBe(1);

    app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);

    expect(AuditLog::where('action', 'schema.read')->count())->toBe(1);
});

test('MySQL and MariaDB list the connection\'s own database only', function () {
    if (! IntegrationTarget::isMysqlFamily()) {
        $this->markTestSkipped('MySQL and MariaDB only: the other drivers list every schema they can see.');
    }

    // A second database the login can see, with a table in it.
    $other = $this->it->table('other_db');
    $admin = $this->it->admin();
    $admin->statement("CREATE DATABASE IF NOT EXISTS {$other}");
    $admin->statement("CREATE TABLE {$other}.elsewhere (id INT NOT NULL)");

    try {
        $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);
    } finally {
        $admin->statement("DROP DATABASE IF EXISTS {$other}");
    }

    $names = array_column($tree['schemas'], 'name');

    expect($names)->toBe([$tree['default_schema']])
        ->and(itSchemaTable($tree, 'elsewhere'))->toBeNull()
        ->and(itSchemaTable($tree, $this->it->table('items')))->not->toBeNull();
});

test('the default schema is listed first and survives a cut at the size cap', function () {
    config(['queryproxy.schema_max_columns' => 2]);

    $tree = app(SchemaReader::class)->read($this->it->connection(), $this->it->owner);

    expect($tree['default_schema'])->not->toBeNull()
        ->and($tree['schemas'][0]['name'])->toBe($tree['default_schema']);
});

test('the read-only login of a connection reads the catalog and sees the table it may read', function () {
    $connection = $this->it->connection(withReader: true);
    $this->it->grantReader($this->it->table('items'));

    $tree = app(SchemaReader::class)->read($connection, $this->it->owner);

    expect(itSchemaTable($tree, $this->it->table('items')))->not->toBeNull();
});
