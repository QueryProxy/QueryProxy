<?php

use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Connections\TlsPemStore;
use App\Services\Users\UserLifecycle;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * Transactions that write an audit record are opened with `attempts`: on
 * MariaDB 11.8 (`innodb_snapshot_isolation`) a concurrent audit write fails
 * the chain lock row with error 1020, which Laravel retries only on the
 * outermost transaction. SQLite cannot raise that error by itself, so it is
 * injected from the `creating` event of the audit record, which fires inside
 * the transaction AuditLog opens (nested whenever the caller has one).
 *
 * RefreshDatabase wraps every test in a transaction, which would make the
 * transaction under test a nested one (never retried). These tests therefore
 * run on a throwaway SQLite file, where the transaction under test is the
 * outermost one, as it is in production.
 */

beforeEach(function () {
    $this->retryDatabaseFile = tempnam(sys_get_temp_dir(), 'qp-audit-retry-');

    config(['database.connections.audit_retry' => [
        ...config('database.connections.sqlite'),
        'database' => $this->retryDatabaseFile,
    ]]);

    $this->originalDefaultConnection = DB::getDefaultConnection();
    DB::setDefaultConnection('audit_retry');

    Artisan::call('migrate', ['--database' => 'audit_retry', '--force' => true]);
});

/**
 * Make the next $failures audit writes of $action fail like MariaDB 11.8 does.
 *
 * @return object{attempts: int}
 */
function failAuditWrites(string $action, int $failures): object
{
    $state = (object) ['attempts' => 0];

    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) use ($action, $failures, $state) {
        if ($log->action !== $action) {
            return;
        }

        if (++$state->attempts <= $failures) {
            throw new QueryException(
                'sqlite',
                'update `audit_chain_lock` set `sequence` = `sequence` + 1 where `id` = ?',
                [1],
                new PDOException("SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'audit_chain_lock'", 1020),
            );
        }
    });

    return $state;
}

/**
 * Make the next $failures deletes of a team fail with 1020 right after the row
 * was deleted, i.e. after Team's own `deleted` hook ran (its listener was
 * registered first). Models the cascade failing mid-DELETE on MariaDB 11.8.
 *
 * @return object{attempts: int}
 */
function failTeamDeletes(int $failures): object
{
    $state = (object) ['attempts' => 0];

    Event::listen('eloquent.deleted: '.Team::class, function () use ($failures, $state) {
        if (++$state->attempts <= $failures) {
            throw new QueryException(
                'sqlite',
                'delete from `teams` where `id` = ?',
                [1],
                new PDOException("SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'connections'", 1020),
            );
        }
    });

    return $state;
}

/** Write a file where a connection's TLS PEM files live. */
function writeTlsPemFor(Connection $connection): string
{
    $directory = storage_path('app/tls/'.$connection->id);
    is_dir($directory) || mkdir($directory, 0700, true);
    file_put_contents($directory.'/ca.pem', 'pem');

    return $directory;
}

afterEach(function () {
    DB::purge('audit_retry');
    DB::setDefaultConnection($this->originalDefaultConnection);
    @unlink($this->retryDatabaseFile);

    foreach (glob(storage_path('app/tls/*')) ?: [] as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('without attempts a nested audit write that hits 1020 is not retried', function () {
    $state = failAuditWrites('test.nested', 1);

    expect(fn () => DB::transaction(fn () => audit()->record('test.nested')))
        ->toThrow(DeadlockException::class, 'Record has changed since last read in table');

    expect($state->attempts)->toBe(1)
        ->and(AuditLog::where('action', 'test.nested')->count())->toBe(0);
});

test('with attempts the outer transaction is retried and commits', function () {
    $state = failAuditWrites('test.nested', 1);

    $log = DB::transaction(fn () => audit()->record('test.nested'), attempts: 3);

    expect($state->attempts)->toBe(2)
        ->and(AuditLog::where('action', 'test.nested')->count())->toBe(1)
        ->and($log->prev_hash)->not->toBeNull()
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('deleting a user retries after 1020 and cancels the pending requests once', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);
    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    audit()->record('test.before');
    $state = failAuditWrites('user.deleted', 1);

    app(UserLifecycle::class)->delete($user);

    expect($state->attempts)->toBe(2)
        ->and(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id)->trashed())->toBeTrue()
        ->and($request->fresh()->status->value)->toBe('cancelled')
        ->and(AuditLog::where('action', 'request.cancelled')->count())->toBe(1)
        ->and(AuditLog::where('action', 'user.deleted')->count())->toBe(1)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('deleting a user rolls everything back when every attempt hits 1020', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);
    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    $state = failAuditWrites('user.deleted', 3);

    expect(fn () => app(UserLifecycle::class)->delete($user))->toThrow(DeadlockException::class);

    expect($state->attempts)->toBe(3)
        ->and(User::find($user->id))->not->toBeNull()
        ->and($request->fresh()->status->value)->toBe('pending')
        ->and(AuditLog::whereIn('action', ['user.deleted', 'request.cancelled'])->count())->toBe(0);
});

test('suspending a user retries after 1020', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $state = failAuditWrites('user.suspended', 1);

    Livewire::actingAs($admin)->test(Users::class)->call('suspendUser', $user->id);

    expect($state->attempts)->toBe(2)
        ->and($user->fresh()->isSuspended())->toBeTrue()
        ->and(AuditLog::where('action', 'user.suspended')->count())->toBe(1)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('creating a team retries after 1020 and installs the default masking rules once', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $state = failAuditWrites('team.created', 1);

    Livewire::actingAs($admin)->test(Teams::class)->set('name', 'Retry Team')->call('createTeam');

    $teams = Team::where('name', 'Retry Team')->get();

    expect($state->attempts)->toBe(2)
        ->and($teams)->toHaveCount(1)
        ->and($teams->first()->maskingRules()->count())->toBe(count(MaskingRule::defaults()))
        ->and(AuditLog::where('action', 'team.created')->count())->toBe(1)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('deleting a team retries after 1020 and removes the PEM files exactly when it commits', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $directory = writeTlsPemFor($connection);

    $state = failAuditWrites('team.deleted', 1);

    Livewire::actingAs($admin)->test(Teams::class)->call('deleteTeam', $team->id);

    expect($state->attempts)->toBe(2)
        ->and(Team::find($team->id))->toBeNull()
        ->and(file_exists($directory))->toBeFalse()
        ->and(AuditLog::where('action', 'team.deleted')->count())->toBe(1)
        ->and($this->artisan('audit:verify')->run())->toBe(0);
});

test('a team delete that never commits keeps the team and its PEM files', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $directory = writeTlsPemFor($connection);

    $state = failAuditWrites('team.deleted', 3);

    expect(fn () => Livewire::actingAs($admin)->test(Teams::class)->call('deleteTeam', $team->id))
        ->toThrow(DeadlockException::class);

    expect($state->attempts)->toBe(3)
        ->and(Team::find($team->id))->not->toBeNull()
        ->and(is_dir($directory))->toBeTrue()
        ->and(file_exists($directory.'/ca.pem'))->toBeTrue()
        ->and(AuditLog::where('action', 'team.deleted')->count())->toBe(0);
});

test('a team delete that fails after the row was deleted keeps the PEM files until the retry commits', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $directory = writeTlsPemFor($connection);

    $state = failTeamDeletes(1);
    $pemExistedAtRetry = null;

    // Runs before the retry's own delete: the failed attempt must not have
    // purged anything.
    Event::listen('eloquent.deleting: '.Team::class, function () use (&$pemExistedAtRetry, $state, $directory) {
        if ($state->attempts === 1) {
            $pemExistedAtRetry = file_exists($directory.'/ca.pem');
        }
    });

    Livewire::actingAs($admin)->test(Teams::class)->call('deleteTeam', $team->id);

    expect($state->attempts)->toBe(2)
        ->and($pemExistedAtRetry)->toBeTrue()
        ->and(Team::find($team->id))->toBeNull()
        ->and(file_exists($directory))->toBeFalse()
        ->and(AuditLog::where('action', 'team.deleted')->count())->toBe(1);
});

test('a team delete that fails after the row was deleted on every attempt keeps the team and its PEM files', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $directory = writeTlsPemFor($connection);

    $state = failTeamDeletes(3);

    expect(fn () => Livewire::actingAs($admin)->test(Teams::class)->call('deleteTeam', $team->id))
        ->toThrow(QueryException::class, 'Record has changed since last read');

    expect($state->attempts)->toBe(3)
        ->and(Team::find($team->id))->not->toBeNull()
        ->and(file_exists($directory.'/ca.pem'))->toBeTrue()
        ->and(AuditLog::where('action', 'team.deleted')->count())->toBe(0);
});

test('purging the PEM files of a team again is harmless', function () {
    $connection = Connection::factory()->create();
    $directory = writeTlsPemFor($connection);
    $store = app(TlsPemStore::class);

    $store->purgeById($connection->id);
    $store->purgeById($connection->id);

    expect(file_exists($directory))->toBeFalse();
});
