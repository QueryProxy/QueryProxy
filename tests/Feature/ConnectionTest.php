<?php

use App\Livewire\Connections\Index;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Connections\DynamicConnectionFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function makeTeamUser(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => $role]);
    session(['current_team_id' => $team->id]);

    return [$team, $user];
}

test('credentials are encrypted at rest', function () {
    $connection = Connection::factory()->create(['password' => 'super-secret']);

    $raw = DB::table('connections')->where('id', $connection->id)->first();

    expect($raw->password)->not->toContain('super-secret')
        ->and($raw->host)->not->toContain('db.internal')
        ->and($connection->fresh()->password)->toBe('super-secret');
});

test('connections page requires the dba role', function () {
    [, $developer] = makeTeamUser('developer');

    $this->actingAs($developer)->get(route('connections.index'))->assertForbidden();
});

test('dba can create a connection', function () {
    [$team, $dba] = makeTeamUser('dba');

    Livewire::actingAs($dba)
        ->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Analytics DB')
        ->set('driver', 'pgsql')
        ->set('host', 'pg.internal')
        ->set('port', '5432')
        ->set('database', 'analytics')
        ->set('username', 'reader')
        ->set('password', 'pw')
        ->call('save')
        ->assertHasNoErrors();

    $connection = Connection::where('name', 'Analytics DB')->first();

    expect($connection)->not->toBeNull()
        ->and($connection->team_id)->toBe($team->id)
        ->and($connection->host)->toBe('pg.internal');
});

test('editing with a blank password keeps the stored password', function () {
    [$team, $dba] = makeTeamUser('dba');
    $connection = Connection::factory()->create(['team_id' => $team->id, 'password' => 'original']);

    Livewire::actingAs($dba)
        ->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($connection->fresh()->password)->toBe('original')
        ->and($connection->fresh()->name)->toBe('Renamed');
});

test('grants can be toggled for team developers only', function () {
    [$team, $dba] = makeTeamUser('dba');
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $outsider = User::factory()->create();

    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $component = Livewire::actingAs($dba)->test(Index::class);

    $component->call('toggleGrant', $connection->id, $developer->id);
    expect($connection->fresh()->isGrantedTo($developer))->toBeTrue();

    $component->call('toggleGrant', $connection->id, $developer->id);
    expect($connection->fresh()->isGrantedTo($developer))->toBeFalse();

    expect(fn () => $component->call('toggleGrant', $connection->id, $outsider->id))
        ->toThrow(ModelNotFoundException::class);
});

test('use policy honours grants', function () {
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $ungranted = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $team->users()->attach($ungranted, ['role' => 'developer']);

    $connection->grantedUsers()->attach($developer);

    expect($dba->can('use', $connection))->toBeTrue()
        ->and($developer->can('use', $connection))->toBeTrue()
        ->and($ungranted->can('use', $connection))->toBeFalse();
});

test('dynamic factory builds a working sqlite connection and test() succeeds', function () {
    $dbFile = tempnam(sys_get_temp_dir(), 'qp_sqlite_');
    $connection = Connection::factory()->sqlite($dbFile)->create();

    $factory = app(DynamicConnectionFactory::class);
    $result = $factory->test($connection);

    expect($result['ok'])->toBeTrue()
        ->and($result['latency_ms'])->toBeGreaterThanOrEqual(0);

    unlink($dbFile);
});

test('test() reports failures instead of throwing', function () {
    $connection = Connection::factory()->sqlite('/nonexistent/path/db.sqlite')->create();

    $result = app(DynamicConnectionFactory::class)->test($connection);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();
});
