<?php

use App\Livewire\Admin\Teams;
use App\Livewire\Connections\Index;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Secrets\SecretResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const UI_REFERENCE = 'vault://secret/teams/payments/orders#password';
const UI_PASSWORD_SENTINEL = 'ui-p4ss-SENTINEL-5d21';

/** @param  list<string>|null  $prefixes */
function secretUiDba(?array $prefixes = ['vault://secret/teams/payments']): array
{
    $team = Team::factory()->create(['secret_reference_prefixes' => $prefixes]);
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    return [$team, $dba];
}

function secretUiForm(User $dba, array $fields = [])
{
    $component = Livewire::actingAs($dba)->test(Index::class)->call('openCreate')
        ->set('name', 'orders')
        ->set('host', 'pg.internal')
        ->set('database', 'orders')
        ->set('username', 'reader');

    foreach ($fields as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

test('a connection is saved with a secret reference instead of a password', function () {
    [$team, $dba] = secretUiDba();

    secretUiForm($dba, ['credentialSource' => 'vault', 'password' => ' '.UI_REFERENCE.' '])
        ->call('save')
        ->assertHasNoErrors();

    $connection = Connection::where('name', 'orders')->firstOrFail();

    expect($connection->password)->toBe(UI_REFERENCE)
        ->and($connection->hasSecretReferencePassword())->toBeTrue()
        ->and($connection->team_id)->toBe($team->id)
        ->and(DB::table('connections')->where('id', $connection->id)->value('password'))->not->toContain('vault://');
});

test('the read-only password has its own source and can be a reference', function () {
    [, $dba] = secretUiDba();

    secretUiForm($dba, [
        'credentialSource' => 'vault',
        'readCredentialSource' => 'vault',
        'password' => UI_REFERENCE,
        'readUsername' => 'ro',
        'readPassword' => 'vault://secret/teams/payments/orders#ro_password',
    ])->call('save')->assertHasNoErrors();

    expect(Connection::where('name', 'orders')->firstOrFail()->hasSecretReferenceReadPassword())->toBeTrue();
});

test('the plain password mode works as before', function () {
    [, $dba] = secretUiDba(null);

    secretUiForm($dba, ['password' => UI_PASSWORD_SENTINEL])
        ->call('save')
        ->assertHasNoErrors();

    $connection = Connection::where('name', 'orders')->firstOrFail();

    expect($connection->password)->toBe(UI_PASSWORD_SENTINEL)
        ->and($connection->hasSecretReferencePassword())->toBeFalse();
});

test('a reference outside the team paths is refused with an error on the password field', function () {
    [, $dba] = secretUiDba();

    secretUiForm($dba, ['credentialSource' => 'vault', 'password' => 'vault://secret/teams/other/orders#password'])
        ->call('save')
        ->assertHasErrors(['password']);

    expect(Connection::where('name', 'orders')->exists())->toBeFalse();
});

test('a team without paths cannot save a reference, and the form says so', function () {
    [, $dba] = secretUiDba(null);

    $component = secretUiForm($dba)->assertSee('External secret stores are off for this team');

    $component->set('credentialSource', 'vault')
        ->set('password', UI_REFERENCE)
        ->call('save')
        ->assertHasErrors(['password']);

    expect(Connection::where('name', 'orders')->exists())->toBeFalse();
});

test('the source options are disabled while the team has no paths', function () {
    [, $dba] = secretUiDba(null);

    secretUiForm($dba)
        ->assertSeeHtml('value="vault" disabled')
        ->assertSeeHtml('value="aws-sm" disabled')
        ->assertSeeHtml('value="azure-kv" disabled');
});

test('the source options are enabled and the team paths are listed once it has some', function () {
    [, $dba] = secretUiDba();

    secretUiForm($dba)
        ->assertDontSeeHtml('value="vault" disabled')
        ->assertSee('vault://secret/teams/payments')
        ->assertDontSee('External secret stores are off for this team');
});

test('a reference box refuses a value of another scheme and a plain box refuses a reference', function () {
    [, $dba] = secretUiDba(['vault://secret', 'aws-sm://prod']);

    secretUiForm($dba, ['credentialSource' => 'aws-sm', 'password' => UI_REFERENCE])
        ->call('save')
        ->assertHasErrors(['password']);

    secretUiForm($dba, ['credentialSource' => 'plain', 'password' => UI_REFERENCE])
        ->call('save')
        ->assertHasErrors(['password']);

    secretUiForm($dba, ['credentialSource' => 'vault', 'password' => ''])
        ->call('save')
        ->assertHasErrors(['password']);

    expect(Connection::where('name', 'orders')->exists())->toBeFalse();
});

test('a tampered credential source is rejected', function () {
    [, $dba] = secretUiDba();

    secretUiForm($dba, ['credentialSource' => 'gopher', 'password' => 'x'])
        ->call('save')
        ->assertHasErrors(['credentialSource']);
});

test('switching a source empties only its own password box', function () {
    [, $dba] = secretUiDba();

    secretUiForm($dba, ['password' => 'typed-plain', 'readPassword' => 'typed-plain-ro'])
        ->set('credentialSource', 'vault')
        ->assertSet('password', '')
        ->assertSet('readPassword', 'typed-plain-ro')
        ->set('readCredentialSource', 'vault')
        ->assertSet('readPassword', '');
});

test('a plain main password and a referenced read-only password can be saved and edited together', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create([
        'team_id' => $team->id, 'name' => 'mixed', 'password' => UI_PASSWORD_SENTINEL,
        'read_username' => 'ro', 'read_password' => 'vault://secret/teams/payments/orders#ro_password',
    ]);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->assertSet('credentialSource', 'plain')
        ->assertSet('readCredentialSource', 'vault')
        ->assertSet('readPassword', 'vault://secret/teams/payments/orders#ro_password')
        ->set('name', 'mixed2')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $connection->fresh();

    expect($fresh->name)->toBe('mixed2')
        ->and($fresh->password)->toBe(UI_PASSWORD_SENTINEL)
        ->and($fresh->hasSecretReferenceReadPassword())->toBeTrue();
});

test('switching to SQLite drops the reference and the form saves', function () {
    config(['queryproxy.sqlite_allowed_dir' => null]);
    $dbFile = tempnam(sys_get_temp_dir(), 'qp_sqlite_');
    [, $dba] = secretUiDba();

    secretUiForm($dba, ['credentialSource' => 'vault', 'password' => UI_REFERENCE])
        ->set('driver', 'sqlite')
        ->assertSet('password', '')
        ->assertSet('credentialSource', 'plain')
        ->set('database', $dbFile)
        ->call('save')
        ->assertHasNoErrors();

    expect(Connection::where('name', 'orders')->firstOrFail()->hasSecretReferencePassword())->toBeFalse();

    @unlink($dbFile);
});

test('a SQLite save does not trip over a leftover reference in a hidden box', function () {
    config(['queryproxy.sqlite_allowed_dir' => null]);
    $dbFile = tempnam(sys_get_temp_dir(), 'qp_sqlite_');
    [, $dba] = secretUiDba();

    secretUiForm($dba, ['driver' => 'sqlite', 'database' => $dbFile])
        ->set('password', UI_REFERENCE)
        ->call('save')
        ->assertHasNoErrors();

    @unlink($dbFile);
});

test('the edit form shows the stored reference and never the stored password', function () {
    [$team, $dba] = secretUiDba();
    $withReference = Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);
    $withPassword = Connection::factory()->create(['team_id' => $team->id, 'name' => 'pw', 'password' => UI_PASSWORD_SENTINEL]);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $withReference->id)
        ->assertSet('credentialSource', 'vault')
        ->assertSet('password', UI_REFERENCE)
        ->assertSeeHtml(UI_REFERENCE)
        ->call('openEdit', $withPassword->id)
        ->assertSet('credentialSource', 'plain')
        ->assertSet('password', '')
        ->assertDontSee(UI_PASSWORD_SENTINEL)
        ->assertDontSeeHtml(UI_PASSWORD_SENTINEL);
});

test('saving an edited reference connection keeps the reference, and a changed one is checked', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);

    $component = Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('name', 'renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($connection->fresh()->name)->toBe('renamed')
        ->and($connection->fresh()->password)->toBe(UI_REFERENCE);

    $component->call('openEdit', $connection->id)
        ->set('password', 'vault://secret/teams/other/x#password')
        ->call('save')
        ->assertHasErrors(['password']);

    expect($connection->fresh()->password)->toBe(UI_REFERENCE);
});

test('moving an edited connection from a reference to a password needs the new password', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);

    $component = Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('credentialSource', 'plain')
        ->call('save')
        ->assertHasErrors(['password']);

    expect($connection->fresh()->password)->toBe(UI_REFERENCE);

    $component->set('password', UI_PASSWORD_SENTINEL)->call('save')->assertHasNoErrors();

    expect($connection->fresh()->password)->toBe(UI_PASSWORD_SENTINEL);
});

test('moving an edited connection from a password to a reference needs the reference', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'pw', 'password' => UI_PASSWORD_SENTINEL]);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('credentialSource', 'vault')
        ->call('save')
        ->assertHasErrors(['password']);

    expect($connection->fresh()->password)->toBe(UI_PASSWORD_SENTINEL);
});

test('a plain edit with a blank password keeps the stored password', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'pw', 'password' => UI_PASSWORD_SENTINEL]);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('name', 'pw2')
        ->call('save')
        ->assertHasNoErrors();

    expect($connection->fresh()->password)->toBe(UI_PASSWORD_SENTINEL);
});

test('the list shows the source badge and the reference, never the password', function () {
    [$team, $dba] = secretUiDba();
    Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);
    Connection::factory()->create(['team_id' => $team->id, 'name' => 'pw', 'password' => UI_PASSWORD_SENTINEL]);

    Livewire::actingAs($dba)->test(Index::class)
        ->assertSee('HashiCorp Vault')
        ->assertSee(UI_REFERENCE)
        ->assertSee('Stored password')
        ->assertDontSee(UI_PASSWORD_SENTINEL)
        ->assertDontSee('Outside team paths');
});

test('the list flags a reference that is no longer under the team paths', function () {
    [$team, $dba] = secretUiDba();
    Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);
    $team->forceFill(['secret_reference_prefixes' => null])->save();

    Livewire::actingAs($dba)->test(Index::class)->assertSee('Outside team paths');
});

test('a failed test on a reference connection shows a generic message without the secret', function () {
    config([
        'queryproxy.secrets.cache_ttl' => 0,
        'queryproxy.secrets.vault' => [
            'address' => 'https://vault.test:8200', 'namespace' => '', 'auth' => 'token',
            'token' => 'hvs.UI-TOKEN', 'role_id' => '', 'secret_id' => '',
            'approle_mount' => 'approle', 'kubernetes_role' => '', 'kubernetes_mount' => 'kubernetes',
            'kubernetes_jwt_path' => '/nonexistent', 'kv_version' => '2', 'ca_file' => '',
        ],
    ]);
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => UI_PASSWORD_SENTINEL]]])]);

    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create([
        'team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE, 'host' => '127.0.0.1', 'port' => '1',
    ]);

    // The factory cannot scrub the value (cache off), so the page must not show the driver message.
    $factory = Mockery::mock(DynamicConnectionFactory::class);
    $factory->shouldReceive('test')->once()->andReturn([
        'ok' => false, 'latency_ms' => null,
        'error' => 'FATAL: password authentication failed, password was '.UI_PASSWORD_SENTINEL,
        'tls' => ['state' => 'none', 'mode' => null, 'encrypted' => null],
    ]);
    app()->instance(DynamicConnectionFactory::class, $factory);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('testConnection', $connection->id)
        ->assertSee('hidden because these credentials come from an external secret store')
        ->assertDontSee(UI_PASSWORD_SENTINEL);

    $audit = AuditLog::query()->where('action', 'connection.tested')->latest('id')->first();

    expect(json_encode($audit->metadata))->not->toContain(UI_PASSWORD_SENTINEL);
});

test('a failed test on a plain connection still shows the driver message', function () {
    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'pw', 'password' => UI_PASSWORD_SENTINEL]);

    $factory = Mockery::mock(DynamicConnectionFactory::class);
    $factory->shouldReceive('test')->once()->andReturn([
        'ok' => false, 'latency_ms' => null, 'error' => 'connection refused',
        'tls' => ['state' => 'none', 'mode' => null, 'encrypted' => null],
    ]);
    app()->instance(DynamicConnectionFactory::class, $factory);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('testConnection', $connection->id)
        ->assertSee('connection refused');
});

test('a failed secret read shows the generic resolution message', function () {
    config(['queryproxy.secrets.vault.address' => 'https://vault.test:8200', 'queryproxy.secrets.vault.token' => 'hvs.UI-TOKEN']);
    Http::fake(['vault.test:8200/*' => Http::response(['errors' => ['LEAK-DETAIL']], 403)]);
    app(SecretResolver::class)->flush();

    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('testConnection', $connection->id)
        ->assertSee('The credentials could not be resolved from the external secret store (access denied).')
        ->assertDontSee('LEAK-DETAIL');
});

// Admin: per-team secret paths

function secretUiAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('an administrator adds and removes a team secret path, and the change is audited', function () {
    $team = Team::factory()->create();

    $component = Livewire::actingAs(secretUiAdmin())->test(Teams::class)
        ->call('togglePrefixes', $team->id)
        ->assertSee('No path yet')
        ->set('newPrefix', 'vault://secret/teams/payments/')
        ->call('addPrefix', $team->id)
        ->assertHasNoErrors()
        ->assertSet('newPrefix', '')
        ->assertSee('vault://secret/teams/payments');

    expect($team->fresh()->secretReferencePrefixes())->toBe(['vault://secret/teams/payments'])
        ->and(AuditLog::query()->where('action', 'team.secret_prefixes_changed')->count())->toBe(1);

    $component->call('removePrefix', $team->id, 'vault://secret/teams/payments')
        ->assertSee('No path yet');

    expect($team->fresh()->secretReferencePrefixes())->toBe([])
        ->and(AuditLog::query()->where('action', 'team.secret_prefixes_changed')->count())->toBe(2);
});

test('an invalid team secret path is refused with a message', function (string $prefix) {
    $team = Team::factory()->create();

    Livewire::actingAs(secretUiAdmin())->test(Teams::class)
        ->call('togglePrefixes', $team->id)
        ->set('newPrefix', $prefix)
        ->call('addPrefix', $team->id)
        ->assertHasErrors(['newPrefix']);

    expect($team->fresh()->secretReferencePrefixes())->toBe([]);
})->with([
    'blank' => '   ',
    'wildcard' => 'vault://secret/teams/*',
    'dot dot' => 'vault://secret/../other',
    'key' => 'vault://secret/teams/a#password',
    'no scheme' => 'secret/teams/a',
    'reserved mount' => 'vault://sys/x',
]);

test('a path that overlaps another team is flagged', function () {
    $other = Team::factory()->create(['name' => 'Other Squad', 'secret_reference_prefixes' => ['vault://secret/teams']]);
    $team = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/payments']]);

    Livewire::actingAs(secretUiAdmin())->test(Teams::class)
        ->call('togglePrefixes', $team->id)
        ->assertSee('overlaps with a path of Other Squad');

    expect($other->fresh()->secretReferencePrefixes())->toBe(['vault://secret/teams']);
});

test('only a system administrator can change team secret paths', function () {
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);

    Livewire::actingAs($dba)->test(Teams::class)
        ->set('newPrefix', 'vault://secret/teams/payments')
        ->call('addPrefix', $team->id)
        ->assertForbidden();

    expect($team->fresh()->secretReferencePrefixes())->toBe([]);
});

test('the teams list shows how many secret paths each team has', function () {
    Team::factory()->create(['name' => 'Pay Team', 'secret_reference_prefixes' => ['vault://secret/a', 'aws-sm://b']]);

    Livewire::actingAs(secretUiAdmin())->test(Teams::class)->assertSee('2 paths');
});

test('a failed test with a cached secret scrubs the resolved value from the driver message', function () {
    config([
        'queryproxy.secrets.cache_ttl' => 300,
        'queryproxy.secrets.vault' => [
            'address' => 'https://vault.test:8200', 'namespace' => '', 'auth' => 'token',
            'token' => 'hvs.UI-TOKEN', 'role_id' => '', 'secret_id' => '',
            'approle_mount' => 'approle', 'kubernetes_role' => '', 'kubernetes_mount' => 'kubernetes',
            'kubernetes_jwt_path' => '/nonexistent', 'kv_version' => '2', 'ca_file' => '',
        ],
    ]);
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => UI_PASSWORD_SENTINEL]]])]);
    app(SecretResolver::class)->flush();

    [$team, $dba] = secretUiDba();
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'ref', 'password' => UI_REFERENCE]);

    // Resolves the reference (so it is cached) and then fails with a driver message that carries the value.
    $factory = Mockery::mock(DynamicConnectionFactory::class)->makePartial();
    $factory->shouldReceive('configure')->andReturnUsing(function (Connection $connection) {
        app(SecretResolver::class)->resolve($connection->password, $connection, 'password');

        throw new RuntimeException('FATAL: login failed with password '.UI_PASSWORD_SENTINEL);
    });
    app()->instance(DynamicConnectionFactory::class, $factory);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('testConnection', $connection->id)
        ->assertSee('[redacted]')
        ->assertDontSee(UI_PASSWORD_SENTINEL);
});

test('every secret path action is closed to anyone but a system administrator', function () {
    $team = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/payments']]);
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);

    Livewire::actingAs($dba)->test(Teams::class)->call('togglePrefixes', $team->id)->assertForbidden();
    Livewire::actingAs($dba)->test(Teams::class)->call('removePrefix', $team->id, 'vault://secret/teams/payments')->assertForbidden();

    expect($team->fresh()->secretReferencePrefixes())->toBe(['vault://secret/teams/payments']);
});
