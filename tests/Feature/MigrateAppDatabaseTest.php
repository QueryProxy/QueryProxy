<?php

use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditTriggers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Live\LiveAppDb;

/**
 * Seeds the (in-memory SQLite) application database with rows in tables on
 * both sides of the foreign keys, plus a short audit chain.
 */
function seedAppDatabase(): void
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($admin, ['role' => 'developer']);

    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $request = QueryRequest::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $dba->id]);

    audit()->record('test.first', team: $team, metadata: ['b' => 1, 'a' => ['y' => 2]]);
    audit()->record('test.second', sql: "SELECT 'x'");
    audit()->record('test.third', team: $team, metadata: ['request' => $request->id]);
}

/**
 * @return array{string, string} a throw-away SQLite file and the DSN for it
 */
function migrationTarget(): array
{
    $base = tempnam(sys_get_temp_dir(), 'qp-target-');
    $path = $base.'.sqlite';
    rename($base, $path);

    return [$path, 'sqlite:///'.$path];
}

function targetConnection(string $path): Illuminate\Database\Connection
{
    config(['database.connections.check_target' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge('check_target');

    return DB::connection('check_target');
}

beforeEach(function () {
    $this->originalDefault = DB::getDefaultConnection();
});

afterEach(function () {
    // RefreshDatabase rolls back through the default connection.
    DB::setDefaultConnection($this->originalDefault);
    DB::purge('check_target');
});

it('copies every table to a second database and the audit chain still verifies', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('Audit chain intact: 3 records verified.')
        ->assertExitCode(0);

    $target = targetConnection($path);

    foreach (['users', 'teams', 'team_user', 'connections', 'query_requests', 'audit_logs', 'audit_chain_lock'] as $table) {
        $this->assertSame(DB::table($table)->count(), $target->table($table)->count(), "row count of {$table} on the target");
    }

    // Ids, hashes and the chain head travel unchanged.
    expect($target->table('audit_logs')->orderBy('id')->get()->all())
        ->toEqual(DB::table('audit_logs')->orderBy('id')->get()->all())
        ->and($target->table('audit_chain_lock')->first())
        ->toEqual(DB::table('audit_chain_lock')->first())
        ->and($target->table('users')->orderBy('id')->pluck('password', 'id')->all())
        ->toEqual(DB::table('users')->orderBy('id')->pluck('password', 'id')->all());

    // The triggers are still on the target.
    expect(fn () => $target->table('audit_logs')->update(['action' => 'tampered']))
        ->toThrow(QueryException::class, AuditTriggers::MESSAGE);

    // The chain continues on the target.
    $originalDefault = DB::getDefaultConnection();
    DB::setDefaultConnection('check_target');

    try {
        audit()->record('test.after-move');
        expect($this->artisan('audit:verify')->run())->toBe(0)
            ->and($target->table('audit_logs')->count())->toBe(4);
    } finally {
        DB::setDefaultConnection($originalDefault);
    }
});

it('refuses a target that already holds data', function () {
    seedAppDatabase();
    [, $dsn] = migrationTarget();

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])->assertExitCode(0);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('The target database is not empty')
        ->assertExitCode(1);
});

it('accepts a target that was only migrated', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    targetConnection($path);
    $this->artisan('migrate', ['--database' => 'check_target', '--force' => true])->assertExitCode(0);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])->assertExitCode(0);

    expect(targetConnection($path)->table('users')->count())->toBe(User::count());
});

it('leaves a migrated, empty target when the copy fails, and a retry succeeds', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    // A team_user row whose user does not exist: the source tolerates it (the
    // check is deferred to a commit that never comes), the target does not.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $orphanId = DB::table('team_user')->insertGetId(['team_id' => Team::first()->id, 'user_id' => 9999, 'role' => 'developer']);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('rolled back')
        ->assertExitCode(1);

    $target = targetConnection($path);

    foreach (['users', 'teams', 'team_user', 'connections', 'query_requests', 'audit_logs'] as $table) {
        $this->assertSame(0, $target->table($table)->count(), "{$table} must be empty after the rolled back copy");
    }

    expect((int) $target->table('audit_chain_lock')->sum('sequence'))->toBe(0);

    DB::table('team_user')->where('id', $orphanId)->delete();

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('Audit chain intact: 3 records verified.')
        ->assertExitCode(0);

    expect($target->table('team_user')->count())->toBe(DB::table('team_user')->count());
});

it('fails when the row counts differ after the copy', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    // A migrated target (accepted as empty) whose team_user silently drops inserts.
    $target = targetConnection($path);
    $this->artisan('migrate', ['--database' => 'check_target', '--force' => true])->assertExitCode(0);
    $target->unprepared('CREATE TRIGGER drop_team_user BEFORE INSERT ON team_user BEGIN SELECT RAISE(IGNORE); END');

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('MISMATCH')
        ->expectsOutputToContain('Row counts differ')
        ->assertExitCode(1);
});

it('fails when the audit chain does not verify on the target', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    // Same row counts, but the chain head recorded on the target is wrong.
    $target = targetConnection($path);
    $this->artisan('migrate', ['--database' => 'check_target', '--force' => true])->assertExitCode(0);
    $target->unprepared("CREATE TRIGGER tamper_head AFTER INSERT ON audit_chain_lock BEGIN UPDATE audit_chain_lock SET head_hash = 'tampered' WHERE id = NEW.id; END");

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('does not verify')
        ->assertExitCode(1);
});

it('does not create the target SQLite file when the run is cancelled', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();
    unlink($path);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn])
        ->expectsConfirmation('Has the application been stopped, and do you want to copy the data now?', 'no')
        ->assertExitCode(1);

    expect(file_exists($path))->toBeFalse();

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--no-interaction' => true])->assertExitCode(1);

    expect(file_exists($path))->toBeFalse();
});

it('refuses a database that is not a QueryProxy one', function () {
    [$path, $dsn] = migrationTarget();
    $foreign = targetConnection($path);
    $foreign->statement('create table customers (id integer primary key)');

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true])
        ->expectsOutputToContain('no QueryProxy migrations table')
        ->assertExitCode(1);
});

it('writes nothing on a dry run', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();
    unlink($path);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertExitCode(0);

    expect(file_exists($path))->toBeFalse();

    touch($path);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--dry-run' => true])->assertExitCode(0);

    expect(targetConnection($path)->getSchemaBuilder()->getTables())->toBe([]);
});

it('needs a confirmation unless forced', function () {
    seedAppDatabase();
    [$path, $dsn] = migrationTarget();

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn, '--no-interaction' => true])
        ->expectsOutputToContain('Confirmation is required')
        ->assertExitCode(1);

    $this->artisan('queryproxy:migrate-appdb', ['--to' => $dsn])
        ->expectsConfirmation('Has the application been stopped, and do you want to copy the data now?', 'no')
        ->assertExitCode(1);

    expect(targetConnection($path)->getSchemaBuilder()->getTables())->toBe([]);
});

it('refuses the database it reads from', function () {
    config(['database.connections.file_source' => ['driver' => 'sqlite', 'database' => ($path = migrationTarget()[0]), 'prefix' => '']]);
    DB::setDefaultConnection('file_source');

    $this->artisan('queryproxy:migrate-appdb', ['--to' => 'sqlite:///'.$path, '--force' => true])
        ->expectsOutputToContain('same database')
        ->assertExitCode(1);
});

it('rejects an unsupported driver and a missing --to', function () {
    $this->artisan('queryproxy:migrate-appdb', ['--to' => 'redis://localhost:6379/0', '--force' => true])
        ->expectsOutputToContain('needs one of these drivers')
        ->assertExitCode(1);

    $this->artisan('queryproxy:migrate-appdb', ['--force' => true])
        ->expectsOutputToContain('--to=<dsn>')
        ->assertExitCode(1);
});

it('never prints the target password', function () {
    $this->artisan('queryproxy:migrate-appdb', [
        '--to' => 'mysql://qp:S3cr%40tP4ss@127.0.0.1:1/queryproxy',
        '--force' => true,
    ])
        ->expectsOutputToContain('Target: mysql://qp@127.0.0.1:1/queryproxy')
        ->doesntExpectOutputToContain('S3cr')
        ->assertExitCode(1);
});

/*
 * Real server: the application database is seeded in SQLite and moved to the
 * live database of the integration job (QUERYPROXY_LIVE_APPDB_*). Runs with
 * `pest --group=live-appdb`; skipped without a server.
 */
it('moves the application database to a real server', function () {
    if (! LiveAppDb::configured()) {
        LiveAppDb::failIfRequired();
        $this->markTestSkipped('Set '.LiveAppDb::ENV_PREFIX.'HOST to run this against a real server.');
    }

    $config = LiveAppDb::connectionConfig();

    // SQL Server starts without our database; the other live tests create it too.
    LiveAppDb::ensureDatabase();

    if (! str_ends_with((string) $config['database'], '_test') && getenv(LiveAppDb::ENV_PREFIX.'ALLOW_ANY_DATABASE') !== '1') {
        throw new RuntimeException('Refusing to drop every table of a database whose name does not end in "_test".');
    }

    $driver = $config['driver'];
    $dsn = sprintf(
        '%s://%s:%s@%s:%s/%s%s',
        $driver,
        rawurlencode((string) $config['username']),
        rawurlencode((string) $config['password']),
        $config['host'],
        $config['port'],
        rawurlencode((string) $config['database']),
        $driver === 'sqlsrv' ? '?trust_server_certificate=true&encrypt=true' : '',
    );

    $clean = function () use ($config) {
        config(['database.connections.'.LiveAppDb::CONNECTION => $config]);
        DB::purge(LiveAppDb::CONNECTION);
        $live = DB::connection(LiveAppDb::CONNECTION);

        if ($live->getSchemaBuilder()->hasTable('audit_logs')) {
            (new AuditTriggers)->drop($live);
        }

        $live->getSchemaBuilder()->dropAllTables();
    };

    $clean();
    seedAppDatabase();

    try {
        // The command output is kept so that a red CI run shows why. The command
        // never prints the password (it scrubs it), and the server is a throwaway one.
        $output = new BufferedOutput;
        $status = Artisan::call('queryproxy:migrate-appdb', ['--to' => $dsn, '--force' => true], $output);
        $text = $output->fetch();

        // Assertions use assertSame() with a message: Pest's toBe() drops one,
        // and a red CI run has to say which table or step failed.
        $this->assertSame(0, $status, "queryproxy:migrate-appdb failed:\n{$text}");
        $this->assertStringContainsString('Audit chain intact: 3 records verified.', $text, "audit:verify did not report an intact chain:\n{$text}");

        $live = DB::connection(LiveAppDb::CONNECTION);

        // Counts on the source (SQLite), taken before the default connection moves to the live one.
        $sourceCounts = [];

        foreach (['users', 'teams', 'team_user', 'connections', 'query_requests', 'audit_logs'] as $table) {
            $sourceCounts[$table] = DB::table($table)->count();
            $this->assertSame($sourceCounts[$table], $live->table($table)->count(), "row count of {$table} on the live database");
        }

        // The next insert must not collide with the copied ids.
        $next = $live->table('teams')->insertGetId([
            'name' => 'After the move',
            'slug' => 'after-the-move',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan((int) DB::table('teams')->max('id'), $next, 'id of a team inserted after the move (sequence or identity not advanced)');

        // Deleting works on the moved data. On SQL Server some foreign keys are
        // NO ACTION (SqlServerReferentialActions) and the models do the rest.
        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(LiveAppDb::CONNECTION);

        try {
            $admin = User::where('is_admin', true)->firstOrFail();
            $owner = User::findOrFail($live->table('query_requests')->value('user_id'));
            $team = Team::where('slug', '!=', 'after-the-move')->orderBy('id')->firstOrFail();
            $connectionId = $live->table('connections')->value('id');

            // Rows for every key that is NO ACTION on SQL Server, so the
            // application-layer deletes are exercised for all eight: the team's
            // requests and masking rules are in the copied data; these are the rest.
            $live->table('connection_user')->insert(['connection_id' => $connectionId, 'user_id' => $owner->id, 'granted_by' => $admin->id]);
            $live->table('connection_grant_requests')->insert([
                'team_id' => $team->id, 'connection_id' => $connectionId, 'user_id' => $owner->id,
                'access_level' => 'read', 'duration_minutes' => 60, 'reason' => 'live test', 'status' => 'approved', 'decided_by' => $admin->id,
            ]);

            // A user who has requests cannot be hard deleted (ADR-018) on any
            // driver, and what refers to them stays as it is.
            $live->table('connections')->update(['created_by' => $owner->id]);
            $refused = false;

            try {
                $owner->forceDelete();
            } catch (QueryException) {
                $refused = true;
            }

            $this->assertTrue($refused, 'forceDelete() of a user with requests must be refused');
            $this->assertSame(
                $live->table('connections')->count(),
                $live->table('connections')->where('created_by', $owner->id)->count(),
                'connections.created_by must stay while the delete of its user is refused',
            );

            $live->table('connections')->update(['created_by' => $admin->id]);
            $admin->forceDelete();

            $this->assertSame(0, $live->table('connections')->whereNotNull('created_by')->count(), 'connections.created_by of a force deleted user must be NULL');
            $this->assertSame(0, $live->table('connection_user')->whereNotNull('granted_by')->count(), 'connection_user.granted_by of a force deleted user must be NULL');
            $this->assertSame(0, $live->table('connection_grant_requests')->whereNotNull('decided_by')->count(), 'connection_grant_requests.decided_by of a force deleted user must be NULL');
            $this->assertSame(1, $live->table('connection_grant_requests')->count(), 'the grant request itself must stay');
            $this->assertSame($sourceCounts['users'] - 1, $live->table('users')->count(), 'users left on the live database after forceDelete()');

            $team->delete();

            foreach (['team_user', 'connections', 'connection_user', 'query_requests', 'masking_rules', 'connection_grant_requests', 'chat_approval_tokens'] as $table) {
                $this->assertSame(0, $live->table($table)->count(), "rows left in {$table} after deleting the team");
            }

            // Only the team that was added after the move is left.
            $this->assertSame(1, $live->table('teams')->count(), 'teams left after deleting the moved team');
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    } finally {
        $clean();
        DB::purge(LiveAppDb::CONNECTION);
    }
})->group('live-appdb');
