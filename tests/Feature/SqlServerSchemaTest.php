<?php

/*
 * SQL Server as the application database, checked without a server.
 *
 * Every migration's up() runs against a SQL Server connection in pretend mode
 * (the statements are compiled by Laravel's SQL Server grammar, nothing is
 * sent), and the foreign keys and indexes that come out are checked for what
 * SQL Server refuses: a table reachable along two cascade paths (error 1785),
 * ON DELETE RESTRICT (it has none), and an index on an nvarchar(max) column.
 * The real run is the live-appdb job (MigrateAppDatabaseTest).
 */

use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Support\SqlServerReferentialActions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * @return array{statements: list<string>, skipped: list<string>}
 */
function sqlServerMigrationStatements(): array
{
    // One data-only migration tries to reach the (absent) server and waits for
    // the connection to fail: compile once for the whole file.
    static $compiled = null;

    if ($compiled !== null) {
        return $compiled;
    }

    config(['database.connections.fake_sqlsrv' => [
        'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'fake', 'username' => 'u', 'password' => 'p', 'prefix' => '', 'login_timeout' => 1,
    ]]);

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('fake_sqlsrv');
    Facade::clearResolvedInstance('db.schema');

    $statements = [];
    $skipped = [];

    try {
        $files = glob(database_path('migrations/*.php'));
        sort($files);

        foreach ($files as $file) {
            $migration = require $file;

            try {
                $queries = DB::connection('fake_sqlsrv')->pretend(fn () => $migration->up());
            } catch (PDOException|QueryException|InvalidArgumentException) {
                // A data-only migration that reads the table: pretend mode
                // cannot answer its SELECT.
                $skipped[] = basename($file);

                continue;
            }

            foreach ($queries as $query) {
                $statements[] = $query['query'];
            }
        }
    } finally {
        DB::setDefaultConnection($original);
        Facade::clearResolvedInstance('db.schema');
        DB::purge('fake_sqlsrv');
    }

    return $compiled = ['statements' => $statements, 'skipped' => $skipped];
}

/**
 * Foreign keys left after all migrations, from the compiled statements.
 *
 * @param  list<string>  $statements
 * @return array<string, array{child: string, parent: string, action: string}> keyed by constraint name
 */
function sqlServerForeignKeys(array $statements): array
{
    $keys = [];

    foreach ($statements as $sql) {
        if (preg_match('/^alter table "(\w+)" add constraint "(\w+)" foreign key \([^)]*\) references "(\w+)" \([^)]*\)(?: on delete (cascade|set null|no action|restrict))?/i', $sql, $m)) {
            $keys[$m[2]] = ['child' => $m[1], 'parent' => $m[3], 'action' => strtolower($m[4] ?? 'no action')];
        } elseif (preg_match('/^alter table "(\w+)" drop constraint "(\w+)"/i', $sql, $m)) {
            unset($keys[$m[2]]);
        }
    }

    return $keys;
}

/**
 * What SQL Server's check looks at: following CASCADE / SET NULL keys from a
 * deleted row, a table must not be reached twice, and the chain must not loop.
 *
 * @param  list<array{child: string, parent: string, action: string}>  $keys
 * @return list<string> problems, empty when SQL Server would accept the keys
 */
function cascadePathProblems(array $keys): array
{
    $children = [];

    foreach ($keys as $key) {
        if (in_array($key['action'], ['cascade', 'set null'], true)) {
            $children[$key['parent']][] = $key['child'];
        }
    }

    $problems = [];

    foreach (array_keys($children) as $root) {
        $reached = [];
        $walk = function (string $table, array $trail) use (&$walk, &$reached, &$problems, $children, $root) {
            foreach ($children[$table] ?? [] as $child) {
                if (in_array($child, $trail, true)) {
                    $problems[] = "cycle from {$root}: ".implode(' > ', [...$trail, $child]);

                    continue;
                }

                $reached[$child] = ($reached[$child] ?? 0) + 1;
                $walk($child, [...$trail, $child]);
            }
        };
        $walk($root, [$root]);

        foreach ($reached as $table => $paths) {
            if ($paths > 1) {
                $problems[] = "{$root} reaches {$table} along {$paths} cascade paths";
            }
        }
    }

    return $problems;
}

test('the application schema has no multiple cascade paths on SQL Server', function () {
    ['statements' => $statements] = sqlServerMigrationStatements();
    $keys = sqlServerForeignKeys($statements);

    // The compile worked and found the keys (guards the parsing itself).
    expect($keys)->toHaveCount(20)
        ->and(cascadePathProblems(array_values($keys)))->toBe([]);
});

test('the same keys with their usual actions would have been refused by SQL Server', function () {
    // The keys as every other driver creates them: what SQLite reports for the
    // migrated schema, with RESTRICT (ADR-018) and the cascades and SET NULLs.
    $keys = [];

    foreach (Schema::getTables() as $table) {
        foreach (Schema::getForeignKeys($table['name']) as $key) {
            $keys[] = ['child' => $table['name'], 'parent' => $key['foreign_table'], 'action' => str_replace('_', ' ', strtolower($key['on_delete']))];
        }
    }

    expect(cascadePathProblems($keys))->not->toBe([])
        ->and(cascadePathProblems([
            ['child' => 'a', 'parent' => 'root', 'action' => 'cascade'],
            ['child' => 'a', 'parent' => 'b', 'action' => 'cascade'],
            ['child' => 'b', 'parent' => 'root', 'action' => 'cascade'],
        ]))->toBe(['root reaches a along 2 cascade paths']);
});

test('every key the SQL Server substitution names exists, and only those differ', function () {
    ['statements' => $statements] = sqlServerMigrationStatements();
    $sqlServer = [];

    foreach (sqlServerForeignKeys($statements) as $key) {
        $sqlServer[$key['child'].'|'.$key['parent'].'|'.$key['action']] = true;
    }

    $differing = [];

    foreach (Schema::getTables() as $table) {
        foreach (Schema::getForeignKeys($table['name']) as $key) {
            $column = $key['columns'][0];
            $usual = str_replace('_', ' ', strtolower($key['on_delete']));
            $listed = in_array($table['name'].'.'.$column, SqlServerReferentialActions::NO_ACTION, true);

            // Listed keys are NO ACTION on SQL Server; every other key is the same there.
            $this->assertTrue(
                isset($sqlServer[$table['name'].'|'.$key['foreign_table'].'|'.($listed ? 'no action' : ($usual === 'restrict' ? 'no action' : $usual))]),
                "foreign key {$table['name']}.{$column} has the wrong action on SQL Server",
            );

            if ($listed) {
                $differing[] = $table['name'].'.'.$column;
            }
        }
    }

    expect($differing)->toEqualCanonicalizing(SqlServerReferentialActions::NO_ACTION);
});

test('the SQL Server statements use nothing SQL Server lacks', function () {
    ['statements' => $statements, 'skipped' => $skipped] = sqlServerMigrationStatements();

    expect($skipped)->toBe(['2026_10_21_000006_record_tls_settings_on_connections.php']);

    $maxColumns = [];

    foreach ($statements as $sql) {
        $this->assertDoesNotMatchRegularExpression('/on delete restrict/i', $sql, 'SQL Server has no ON DELETE RESTRICT');

        if (preg_match('/^create table "(\w+)" \((.*)\)$/s', $sql, $m)) {
            preg_match_all('/"(\w+)" nvarchar\(max\)/', $m[2], $columns);
            $maxColumns[$m[1]] = $columns[1];
        } elseif (preg_match('/^alter table "(\w+)" add "(\w+)" nvarchar\(max\)/', $sql, $m)) {
            $maxColumns[$m[1]][] = $m[2];
        }
    }

    foreach ($statements as $sql) {
        if (preg_match('/^create (?:unique )?index "\w+" on "(\w+)" \((.*)\)/i', $sql, $m)) {
            preg_match_all('/"(\w+)"/', $m[2], $indexed);

            $this->assertSame([], array_values(array_intersect($indexed[1], $maxColumns[$m[1]] ?? [])), "index on an nvarchar(max) column: {$sql}");
        }
    }
});

test('releaseTeamRows removes what the NO ACTION keys would not cascade', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $dba = User::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $otherConnection = Connection::factory()->create(['team_id' => $other->id]);
    QueryRequest::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $dba->id]);
    QueryRequest::factory()->create(['team_id' => $other->id, 'connection_id' => $otherConnection->id, 'user_id' => $dba->id]);

    DB::table('connection_grant_requests')->insert([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $dba->id,
        'access_level' => 'read', 'duration_minutes' => 60, 'reason' => 'x', 'status' => 'pending',
    ]);

    expect(DB::table('masking_rules')->where('team_id', $team->id)->count())->toBeGreaterThan(0);

    SqlServerReferentialActions::releaseTeamRows(DB::connection(), $team->id);

    expect(DB::table('query_requests')->where('team_id', $team->id)->count())->toBe(0)
        ->and(DB::table('masking_rules')->where('team_id', $team->id)->count())->toBe(0)
        ->and(DB::table('connection_grant_requests')->where('team_id', $team->id)->count())->toBe(0)
        // The team itself, its connection and the other team's rows are untouched.
        ->and(Team::find($team->id))->not->toBeNull()
        ->and(Connection::find($connection->id))->not->toBeNull()
        ->and(DB::table('query_requests')->where('team_id', $other->id)->count())->toBe(1)
        ->and(DB::table('masking_rules')->where('team_id', $other->id)->count())->toBeGreaterThan(0);
});

test('releaseUserReferences leaves the references alone while the user still has requests', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'created_by' => $user->id]);
    QueryRequest::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $user->id]);

    // The delete is going to be refused (ADR-018); nothing may be lost meanwhile.
    SqlServerReferentialActions::releaseUserReferences(DB::connection(), $user->id);

    expect($connection->fresh()->created_by)->toBe($user->id);

    $reviewer = User::factory()->create();
    $reviewed = Connection::factory()->create(['team_id' => $team->id, 'created_by' => $reviewer->id]);
    QueryRequest::query()->update(['reviewed_by' => $reviewer->id]);

    SqlServerReferentialActions::releaseUserReferences(DB::connection(), $reviewer->id);

    expect($reviewed->fresh()->created_by)->toBe($reviewer->id);
});

test('releaseUserReferences sets the references to the user to NULL', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $kept = User::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'created_by' => $user->id]);
    $other = Connection::factory()->create(['team_id' => $team->id, 'created_by' => $kept->id]);
    DB::table('connection_user')->insert(['connection_id' => $connection->id, 'user_id' => $kept->id, 'granted_by' => $user->id]);
    DB::table('connection_grant_requests')->insert([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $kept->id,
        'access_level' => 'read', 'duration_minutes' => 60, 'reason' => 'x', 'status' => 'approved', 'decided_by' => $user->id,
    ]);

    SqlServerReferentialActions::releaseUserReferences(DB::connection(), $user->id);

    expect($connection->fresh()->created_by)->toBeNull()
        ->and(DB::table('connection_user')->value('granted_by'))->toBeNull()
        ->and(DB::table('connection_grant_requests')->value('decided_by'))->toBeNull()
        ->and($other->fresh()->created_by)->toBe($kept->id);
});
