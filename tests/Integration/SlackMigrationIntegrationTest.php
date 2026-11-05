<?php

/*
 * The Slack app migration (2026_11_01_000020_add_slack_app_columns) on a real
 * server, with data in the tables.
 *
 * The migration relaxes two NOT NULL columns with ->change() and adds, then
 * drops again, columns, an index and a foreign key. ->change() is spelled
 * differently on every driver (ALTER COLUMN ... DROP NOT NULL on PostgreSQL,
 * MODIFY on MySQL, ALTER COLUMN on SQL Server), and the rest of the suite
 * migrates a SQLite application database only. Here the application schema is
 * built up to the migration in a database of its own on the server, rows are
 * written, and the migration runs up and down over them:
 *
 *  - up: existing rows keep their values (also a long one), both columns accept
 *    NULL, the new columns are there and default as documented;
 *  - down: rows that only exist because of the app are removed, rows of the
 *    webhook mode survive unchanged, the columns are gone and the two columns
 *    are NOT NULL again.
 *
 * PostgreSQL, MySQL, MariaDB and SQL Server only: CockroachDB and YugabyteDB
 * are target databases, never the application database.
 *
 * Group "integration"; skipped without QUERYPROXY_IT_* (see IntegrationTarget).
 */

use App\Enums\DbDriver;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Integration\IntegrationTarget;

const SLACK_MIGRATION_FILE = '2026_11_01_000020_add_slack_app_columns.php';

const SLACK_MIGRATION_CONNECTION = 'it_slack_migration';

const SLACK_MIGRATION_ADMIN = 'it_slack_migration_admin';

beforeEach(function () {
    if (! IntegrationTarget::configured()) {
        IntegrationTarget::failIfRequired('the server is not configured.');
        $this->markTestSkipped(IntegrationTarget::SKIP_MESSAGE);
    }

    if (IntegrationTarget::isCockroach() || IntegrationTarget::isYugabyte()) {
        $this->markTestSkipped('CockroachDB and YugabyteDB are target databases only, never the application database.');
    }

    $this->originalDefault = DB::getDefaultConnection();
    $this->migrationDatabase = 'qp_it_'.bin2hex(random_bytes(4)).'_slack';

    // The administrator session that creates and drops the database of this
    // test. On SQL Server it works from master, so it does not depend on the
    // main test database existing.
    $adminConfig = IntegrationTarget::adminConfig();

    if (IntegrationTarget::driver() === DbDriver::Sqlsrv) {
        $adminConfig['database'] = 'master';
    }

    config(['database.connections.'.SLACK_MIGRATION_ADMIN => $adminConfig]);

    $this->createdDatabase = false;
    itSlackMigrationAdmin()->statement(itSlackCreateDatabaseStatement($this->migrationDatabase));
    $this->createdDatabase = true;

    config(['database.connections.'.SLACK_MIGRATION_CONNECTION => ['database' => $this->migrationDatabase] + IntegrationTarget::adminConfig()]);
    DB::purge(SLACK_MIGRATION_CONNECTION);
    DB::setDefaultConnection(SLACK_MIGRATION_CONNECTION);
});

afterEach(function () {
    if (! isset($this->originalDefault)) {
        return;
    }

    try {
        DB::purge(SLACK_MIGRATION_CONNECTION);
        DB::setDefaultConnection($this->originalDefault);

        if ($this->createdDatabase) {
            itSlackMigrationAdmin()->statement(itSlackDropDatabaseStatement($this->migrationDatabase));
        }
    } finally {
        DB::purge(SLACK_MIGRATION_ADMIN);
    }
});

function itSlackMigrationAdmin(): DatabaseConnection
{
    return DB::connection(SLACK_MIGRATION_ADMIN);
}

function itSlackCreateDatabaseStatement(string $name): string
{
    return match (IntegrationTarget::driver()) {
        DbDriver::Pgsql => "CREATE DATABASE \"{$name}\"",
        DbDriver::Sqlsrv => "CREATE DATABASE [{$name}]",
        default => "CREATE DATABASE `{$name}`",
    };
}

function itSlackDropDatabaseStatement(string $name): string
{
    return match (IntegrationTarget::driver()) {
        // WITH (FORCE) needs PostgreSQL 13; the server is 17.
        DbDriver::Pgsql => "DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)",
        DbDriver::Sqlsrv => "IF DB_ID(N'{$name}') IS NOT NULL BEGIN ALTER DATABASE [{$name}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [{$name}]; END",
        default => "DROP DATABASE IF EXISTS `{$name}`",
    };
}

/** The migration files that run before the Slack migration, relative to the base path. */
function itSlackEarlierMigrations(): array
{
    $files = collect(glob(database_path('migrations/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path))
        ->sort()
        ->values();

    expect($files->contains(SLACK_MIGRATION_FILE))->toBeTrue();

    return $files
        ->filter(fn (string $name): bool => strcmp($name, SLACK_MIGRATION_FILE) < 0)
        ->map(fn (string $name): string => 'database/migrations/'.$name)
        ->all();
}

function itSlackMigration(): object
{
    return require database_path('migrations/'.SLACK_MIGRATION_FILE);
}

/**
 * Whether $e is the server refusing NULL for a NOT NULL column: SQLSTATE 23502
 * on PostgreSQL, driver error 1048 on MySQL and MariaDB, 515 on SQL Server.
 */
function itSlackIsNotNullViolation(QueryException $e): bool
{
    $info = $e->errorInfo ?? [];

    return ($info[0] ?? null) === '23502' || in_array((int) ($info[1] ?? 0), [1048, 515], true);
}

/** Whether inserting NULL into $column of chat_integrations is accepted. */
function itSlackAcceptsNull(string $column, int $teamId, string $provider): bool
{
    $row = [
        'team_id' => $teamId,
        'provider' => $provider,
        'webhook_url' => 'https://hooks.example.test/x',
        'signing_secret' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $row[$column] = null;

    try {
        DB::table('chat_integrations')->insert($row);
    } catch (QueryException $e) {
        // Only a NOT NULL violation counts as "refused"; any other failure
        // (foreign key, unique, missing column) is not what this probe asks.
        if (! itSlackIsNotNullViolation($e)) {
            throw $e;
        }

        return false;
    }

    DB::table('chat_integrations')->where('provider', $provider)->delete();

    return true;
}

test('the Slack app migration goes up and down over existing rows on a real application database', function () {
    $earlier = itSlackEarlierMigrations();

    expect(Artisan::call('migrate', ['--database' => SLACK_MIGRATION_CONNECTION, '--path' => $earlier, '--force' => true]))
        ->toBe(0, Artisan::output());

    expect(Schema::hasTable('chat_integrations'))->toBeTrue()
        ->and(Schema::hasColumn('chat_integrations', 'bot_token'))->toBeFalse()
        ->and(Schema::hasColumn('query_requests', 'slack_ts'))->toBeFalse();

    // Rows of the webhook mode, one with a value longer than a varchar(255).
    $longSecret = str_repeat('s3cr3t-', 400);
    $now = now();

    $firstTeam = DB::table('teams')->insertGetId(['name' => 'First', 'slug' => 'first', 'created_at' => $now, 'updated_at' => $now]);
    $secondTeam = DB::table('teams')->insertGetId(['name' => 'Second', 'slug' => 'second', 'created_at' => $now, 'updated_at' => $now]);

    DB::table('chat_integrations')->insert([
        ['team_id' => $firstTeam, 'provider' => 'slack', 'webhook_url' => 'https://hooks.example.test/first', 'signing_secret' => $longSecret, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => $secondTeam, 'provider' => 'slack', 'webhook_url' => 'https://hooks.example.test/second', 'signing_secret' => 'second-secret', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
    ]);

    // Before: both columns refuse NULL.
    expect(itSlackAcceptsNull('webhook_url', $firstTeam, 'probe-before-1'))->toBeFalse()
        ->and(itSlackAcceptsNull('signing_secret', $firstTeam, 'probe-before-2'))->toBeFalse();

    $migration = itSlackMigration();
    $migration->up();

    // After up: the rows are as they were.
    $rows = DB::table('chat_integrations')->orderBy('team_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->webhook_url)->toBe('https://hooks.example.test/first')
        ->and($rows[0]->signing_secret)->toBe($longSecret)
        ->and((bool) $rows[0]->enabled)->toBeTrue()
        ->and($rows[0]->bot_token)->toBeNull()
        ->and($rows[0]->slack_team_id)->toBeNull()
        ->and((bool) $rows[0]->dm_approvers)->toBeFalse()
        ->and($rows[1]->webhook_url)->toBe('https://hooks.example.test/second')
        ->and($rows[1]->signing_secret)->toBe('second-secret')
        ->and((bool) $rows[1]->enabled)->toBeFalse();

    expect(Schema::hasColumns('chat_integrations', ['bot_token', 'bot_user_id', 'slack_team_id', 'slack_team_name', 'slack_channel_id', 'dm_approvers', 'installed_by', 'installed_at']))->toBeTrue()
        ->and(Schema::hasColumns('query_requests', ['slack_channel', 'slack_ts']))->toBeTrue()
        // The columns of the webhook mode accept NULL now.
        ->and(itSlackAcceptsNull('webhook_url', $firstTeam, 'probe-after-1'))->toBeTrue()
        ->and(itSlackAcceptsNull('signing_secret', $firstTeam, 'probe-after-2'))->toBeTrue();

    // An installation made through OAuth: a bot token, no webhook, no secret.
    DB::table('chat_integrations')->insert([
        'team_id' => $firstTeam, 'provider' => 'slack-app', 'webhook_url' => null, 'signing_secret' => null,
        'bot_token' => 'encrypted-token', 'slack_team_id' => 'T0123', 'slack_channel_id' => 'C0123',
        'dm_approvers' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    expect(DB::table('chat_integrations')->count())->toBe(3);

    $migration->down();

    // After down: the app-only row is gone, the webhook rows are untouched.
    $rows = DB::table('chat_integrations')->orderBy('team_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('provider')->all())->toBe(['slack', 'slack'])
        ->and($rows[0]->webhook_url)->toBe('https://hooks.example.test/first')
        ->and($rows[0]->signing_secret)->toBe($longSecret)
        ->and($rows[1]->webhook_url)->toBe('https://hooks.example.test/second')
        ->and($rows[1]->signing_secret)->toBe('second-secret')
        ->and((bool) $rows[1]->enabled)->toBeFalse();

    expect(Schema::hasColumn('chat_integrations', 'bot_token'))->toBeFalse()
        ->and(Schema::hasColumn('chat_integrations', 'installed_by'))->toBeFalse()
        ->and(Schema::hasColumn('chat_integrations', 'dm_approvers'))->toBeFalse()
        ->and(Schema::hasColumn('query_requests', 'slack_channel'))->toBeFalse()
        ->and(Schema::hasColumn('query_requests', 'slack_ts'))->toBeFalse()
        // NOT NULL again.
        ->and(itSlackAcceptsNull('webhook_url', $firstTeam, 'probe-down-1'))->toBeFalse()
        ->and(itSlackAcceptsNull('signing_secret', $firstTeam, 'probe-down-2'))->toBeFalse();

    // And up once more over the rolled-back schema, so a rollback leaves nothing behind.
    $migration->up();

    expect(DB::table('chat_integrations')->count())->toBe(2)
        ->and(Schema::hasColumn('chat_integrations', 'bot_token'))->toBeTrue()
        ->and(itSlackAcceptsNull('webhook_url', $firstTeam, 'probe-again'))->toBeTrue();
});
