<?php

use App\Livewire\Admin\Teams;
use App\Livewire\Connections\Index;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Connections\TlsPemStore;
use App\Services\Execution\TargetSession;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** @return array{cert: string, key: string} a throw-away self-signed certificate and its key */
function tlsTestPems(): array
{
    static $pems = null;

    if ($pems === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'queryproxy-test'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $cert);
        openssl_pkey_export($key, $keyPem);
        $pems = ['cert' => $cert, 'key' => $keyPem];
    }

    return $pems;
}

function tlsDbaUser(): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    return $user;
}

/** Runs the factory's pdo_mysql attribute lookup the same way the code under test does. */
function tlsMysqlAttribute(string $name): int
{
    return defined('Pdo\\Mysql::ATTR_'.$name) ? constant('Pdo\\Mysql::ATTR_'.$name) : constant('PDO::MYSQL_ATTR_'.$name);
}

function tlsFactoryWithoutSqlsrv(): DynamicConnectionFactory
{
    return new class(app(TargetSession::class)) extends DynamicConnectionFactory
    {
        protected function availablePdoDrivers(): array
        {
            return ['pgsql', 'mysql', 'sqlite', 'dblib'];
        }
    };
}

afterEach(function () {
    $directory = storage_path('app/tls');

    foreach (glob($directory.'/*') ?: [] as $dir) {
        foreach (glob($dir.'/{,.}*.pem', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

// --- defaults for new connections -------------------------------------------

test('new connections require TLS on every network driver', function (string $driver, array $expected) {
    $connection = Connection::factory()->create(['driver' => $driver]);

    expect($connection->fresh()->options['tls'])->toBe($expected)
        ->and($connection->tlsState())->toBe(Connection::TLS_ENFORCED);
})->with([
    'pgsql' => ['pgsql', ['mode' => 'require']],
    'mysql' => ['mysql', ['mode' => 'required', 'verify_server_cert' => false]],
    'mariadb' => ['mariadb', ['mode' => 'required', 'verify_server_cert' => false]],
    'sqlsrv' => ['sqlsrv', ['mode' => 'required', 'trust_server_certificate' => false]],
]);

test('sqlite is outside the TLS rule', function () {
    $connection = Connection::factory()->sqlite()->create();

    expect($connection->fresh()->options)->toBeNull()
        ->and($connection->tlsState())->toBe(Connection::TLS_NONE);
});

test('a TLS setting given at creation is not overwritten by the defaults', function () {
    $connection = Connection::factory()->create(['options' => ['tls' => ['mode' => 'verify-full']]]);

    expect($connection->fresh()->options['tls'])->toBe(['mode' => 'verify-full']);
});

// --- connections that predate the setting -----------------------------------

test('a stored connection without a TLS setting keeps its behaviour and is flagged', function (string $driver, string $state, ?string $mode) {
    $connection = Connection::factory()->make(['driver' => $driver]);

    expect($connection->tlsState())->toBe($state)
        ->and($connection->tlsMode())->toBe($mode);
})->with([
    'pgsql prefer is not enforced' => ['pgsql', Connection::TLS_NOT_ENFORCED, 'prefer'],
    'mysql is left to the driver' => ['mysql', Connection::TLS_DRIVER_DEFAULT, null],
    'mariadb is left to the driver' => ['mariadb', Connection::TLS_DRIVER_DEFAULT, null],
    'sqlsrv is left to the driver' => ['sqlsrv', Connection::TLS_DRIVER_DEFAULT, null],
]);

test('the migration records the effective setting of existing connections and flags them', function () {
    $insert = fn (string $driver, ?array $options = null) => DB::table('connections')->insertGetId([
        'team_id' => Team::factory()->create()->id,
        'name' => $driver.'-'.uniqid(),
        'driver' => $driver,
        'database' => 'x',
        'options' => $options === null ? null : json_encode($options),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = [
        'pgsql' => $insert('pgsql'),
        'mysql' => $insert('mysql'),
        'mariadb' => $insert('mariadb'),
        'sqlsrv' => $insert('sqlsrv', ['custom' => 'kept']),
        'sqlite' => $insert('sqlite'),
        'already' => $insert('pgsql', ['tls' => ['mode' => 'verify-full']]),
    ];

    $migration = require database_path('migrations/2026_10_21_000006_record_tls_settings_on_connections.php');
    $migration->up();
    $migration->up(); // idempotent

    $options = fn (string $key) => json_decode(DB::table('connections')->where('id', $ids[$key])->value('options'), true);

    expect($options('pgsql')['tls'])->toBe(['mode' => 'prefer', 'flag' => 'not_enforced'])
        ->and($options('mysql')['tls'])->toBe(['mode' => null, 'flag' => 'driver_default'])
        ->and($options('mariadb')['tls'])->toBe(['mode' => null, 'flag' => 'driver_default'])
        ->and($options('sqlsrv'))->toBe(['custom' => 'kept', 'tls' => ['mode' => null, 'flag' => 'driver_default']])
        ->and(DB::table('connections')->where('id', $ids['sqlite'])->value('options'))->toBeNull()
        ->and($options('already')['tls'])->toBe(['mode' => 'verify-full']);

    $migrated = Connection::find($ids['pgsql']);
    expect($migrated->tlsState())->toBe(Connection::TLS_NOT_ENFORCED)
        ->and(Connection::find($ids['mysql'])->tlsState())->toBe(Connection::TLS_DRIVER_DEFAULT);

    $migration->down();

    expect(DB::table('connections')->where('id', $ids['pgsql'])->value('options'))->toBeNull()
        ->and($options('sqlsrv'))->toBe(['custom' => 'kept']);
});

// --- driver configuration ----------------------------------------------------

test('PostgreSQL sslmode is always explicit and legacy rows keep prefer', function () {
    $factory = app(DynamicConnectionFactory::class);

    expect($factory->configFor(Connection::factory()->make(['driver' => 'pgsql']))['sslmode'])->toBe('prefer')
        ->and($factory->configFor(Connection::factory()->create(['driver' => 'pgsql']))['sslmode'])->toBe('require')
        ->and($factory->configFor(Connection::factory()->make(['driver' => 'pgsql', 'options' => ['tls' => ['mode' => 'verify-full']]]))['sslmode'])->toBe('verify-full');
});

test('PostgreSQL gets the PEM files as sslrootcert, sslcert and sslkey', function () {
    $pems = tlsTestPems();
    $connection = Connection::factory()->create(['options' => ['tls' => ['mode' => 'verify-full']]]);
    $connection->setTlsPem('ca', $pems['cert']);
    $connection->setTlsPem('cert', $pems['cert']);
    $connection->setTlsPem('key', $pems['key']);
    $connection->save();

    $config = app(DynamicConnectionFactory::class)->configFor($connection->fresh());
    $directory = storage_path('app/tls/'.$connection->id);

    expect($config['sslrootcert'])->toBe($directory.'/ca.pem')
        ->and($config['sslcert'])->toBe($directory.'/cert.pem')
        ->and($config['sslkey'])->toBe($directory.'/key.pem')
        ->and(file_get_contents($config['sslkey']))->toBe(trim($pems['key'])."\n");
});

test('MySQL and MariaDB always carry a TLS trigger option and an explicit verification flag', function (string $driver) {
    $factory = app(DynamicConnectionFactory::class);

    $options = $factory->configFor(Connection::factory()->create(['driver' => $driver]))['options'];

    // Without a trigger option (CA / cert / key / cipher), VERIFY_SERVER_CERT alone leaves mysqlnd on plaintext.
    expect($options)->toHaveKey(tlsMysqlAttribute('SSL_CIPHER'))
        ->and($options[tlsMysqlAttribute('SSL_VERIFY_SERVER_CERT')])->toBeFalse();

    $verifying = Connection::factory()->create(['driver' => $driver, 'options' => ['tls' => ['mode' => 'required', 'verify_server_cert' => true]]]);

    expect($factory->configFor($verifying)['options'][tlsMysqlAttribute('SSL_VERIFY_SERVER_CERT')])->toBeTrue();
})->with(['mysql', 'mariadb']);

test('MySQL uses the stored PEM files as the TLS trigger', function () {
    $pems = tlsTestPems();
    $connection = Connection::factory()->create(['driver' => 'mysql']);
    $connection->setTlsPem('ca', $pems['cert']);
    $connection->save();

    $options = app(DynamicConnectionFactory::class)->configFor($connection->fresh())['options'];

    expect($options[tlsMysqlAttribute('SSL_CA')])->toBe(storage_path('app/tls/'.$connection->id.'/ca.pem'))
        ->and($options)->not->toHaveKey(tlsMysqlAttribute('SSL_CIPHER'))
        ->and($options[tlsMysqlAttribute('SSL_VERIFY_SERVER_CERT')])->toBeFalse();
});

test('MySQL, MariaDB and SQL Server rows left to the driver default get no TLS value in the connection config', function (string $driver) {
    $config = app(DynamicConnectionFactory::class)->configFor(Connection::factory()->make(['driver' => $driver]));
    $options = $config['options'] ?? [];

    expect($config)->not->toHaveKeys(['encrypt', 'trust_server_certificate', 'sslmode'])
        ->and($options)->not->toHaveKey(tlsMysqlAttribute('SSL_VERIFY_SERVER_CERT'))
        ->and($options)->not->toHaveKey(tlsMysqlAttribute('SSL_CIPHER'));
})->with(['mysql', 'mariadb', 'sqlsrv']);

test('SQL Server gets Encrypt and TrustServerCertificate as true/false strings', function () {
    $factory = new class(app(TargetSession::class)) extends DynamicConnectionFactory
    {
        protected function availablePdoDrivers(): array
        {
            return ['sqlsrv'];
        }
    };

    $config = $factory->configFor(Connection::factory()->create(['driver' => 'sqlsrv']));
    $trusting = $factory->configFor(Connection::factory()->create([
        'driver' => 'sqlsrv',
        'options' => ['tls' => ['mode' => 'required', 'trust_server_certificate' => true]],
    ]));

    expect($config['encrypt'])->toBe('true')
        ->and($config['trust_server_certificate'])->toBe('false')
        ->and($trusting['trust_server_certificate'])->toBe('true');
});

test('SQL Server with required TLS refuses to fall back to the dblib DSN that cannot encrypt', function () {
    $connection = Connection::factory()->create(['driver' => 'sqlsrv']);

    expect(fn () => tlsFactoryWithoutSqlsrv()->configure($connection))
        ->toThrow(RuntimeException::class, 'pdo_sqlsrv');
});

// --- PEM storage -------------------------------------------------------------

test('PEM material is encrypted at rest and round-trips', function () {
    $pems = tlsTestPems();
    $connection = Connection::factory()->create();
    $connection->setTlsPem('ca', $pems['cert']);
    $connection->setTlsPem('key', $pems['key']);
    $connection->save();

    $raw = DB::table('connections')->where('id', $connection->id)->value('options');

    expect($raw)->not->toContain('BEGIN')
        ->and($connection->fresh()->tlsPem('ca'))->toBe(trim($pems['cert'])."\n")
        ->and($connection->fresh()->tlsPem('key'))->toBe(trim($pems['key'])."\n")
        ->and($connection->fresh()->tlsPem('cert'))->toBeNull()
        ->and($connection->fresh()->hasTlsPem('ca'))->toBeTrue();
});

test('PEM files are written 0600 in a 0700 directory and rewritten on every sync', function () {
    $pems = tlsTestPems();
    $connection = Connection::factory()->create();
    $connection->setTlsPem('ca', $pems['cert']);
    $connection->setTlsPem('key', $pems['key']);
    $connection->save();

    $store = app(TlsPemStore::class);
    $paths = $store->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);

    expect(array_keys($paths))->toBe(['ca', 'key'])
        ->and(decoct(fileperms($paths['ca']) & 0777))->toBe('600')
        ->and(decoct(fileperms($paths['key']) & 0777))->toBe('600')
        ->and(decoct(fileperms($directory) & 0777))->toBe('700');

    chmod($paths['ca'], 0644);
    file_put_contents($paths['ca'], 'tampered');
    $store->sync($connection);

    expect(decoct(fileperms($paths['ca']) & 0777))->toBe('600')
        ->and(file_get_contents($paths['ca']))->toBe(trim($pems['cert'])."\n");

    // A removed PEM disappears from disk too.
    $connection->setTlsPem('key', null);
    $store->sync($connection);

    expect(file_exists($directory.'/key.pem'))->toBeFalse()
        ->and(file_exists($directory.'/ca.pem'))->toBeTrue();
});

test('deleting a connection removes its PEM directory', function () {
    $connection = Connection::factory()->create();
    $connection->setTlsPem('ca', tlsTestPems()['cert']);
    $connection->save();
    app(TlsPemStore::class)->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);

    expect(is_dir($directory))->toBeTrue();

    $connection->delete();

    expect(file_exists($directory))->toBeFalse();
});

test('a connection without PEM material writes no files', function () {
    $connection = Connection::factory()->create();

    app(DynamicConnectionFactory::class)->configFor($connection);

    expect(file_exists(storage_path('app/tls/'.$connection->id)))->toBeFalse();
});

// --- connection test action --------------------------------------------------

test('the connection test reports the TLS state', function () {
    $dbPath = tempnam(sys_get_temp_dir(), 'qp-tls-');
    $connection = Connection::factory()->sqlite($dbPath)->create();
    $result = app(DynamicConnectionFactory::class)->test($connection);
    @unlink($dbPath);

    expect($result['tls'])->toBe(['state' => 'none', 'mode' => null, 'encrypted' => null]);

    $pg = Connection::factory()->create(['host' => '127.0.0.1', 'port' => '1']);
    $failed = app(DynamicConnectionFactory::class)->test($pg);

    expect($failed['ok'])->toBeFalse()
        ->and($failed['tls'])->toBe(['state' => 'enforced', 'mode' => 'require', 'encrypted' => null]);
});

// --- connection form ---------------------------------------------------------

test('the form defaults to required TLS per driver', function () {
    $dba = tlsDbaUser();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->assertSet('tlsMode', 'require')
        ->set('driver', 'mysql')->assertSet('tlsMode', 'required')->assertSet('tlsVerifyServerCert', false)
        ->set('driver', 'sqlsrv')->assertSet('tlsMode', 'required')->assertSet('tlsTrustServerCertificate', false)
        ->set('driver', 'pgsql')->assertSet('tlsMode', 'require');
});

test('a connection created through the form requires TLS and stores a pasted CA encrypted', function () {
    $dba = tlsDbaUser();
    $pems = tlsTestPems();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Secure PG')
        ->set('host', 'pg.internal')
        ->set('database', 'app')
        ->set('username', 'reader')
        ->set('tlsMode', 'verify-full')
        ->set('tlsCa', $pems['cert'])
        ->call('save')
        ->assertHasNoErrors();

    $connection = Connection::where('name', 'Secure PG')->first();

    expect($connection->tlsMode())->toBe('verify-full')
        ->and($connection->tlsState())->toBe(Connection::TLS_ENFORCED)
        ->and($connection->hasTlsPem('ca'))->toBeTrue()
        ->and(DB::table('connections')->where('id', $connection->id)->value('options'))->not->toContain('BEGIN');
});

test('a MySQL connection created through the form keeps verification off by default', function () {
    $dba = tlsDbaUser();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('driver', 'mysql')
        ->set('name', 'Secure MySQL')
        ->set('host', 'my.internal')
        ->set('database', 'app')
        ->set('username', 'reader')
        ->call('save')
        ->assertHasNoErrors();

    $tls = Connection::where('name', 'Secure MySQL')->first()->tlsSettings();

    expect($tls['mode'])->toBe('required')
        ->and($tls['verify_server_cert'])->toBeFalse()
        ->and($tls)->not->toHaveKey('flag');
});

test('verify modes need a CA and a client certificate needs its key', function () {
    $dba = tlsDbaUser();
    $pems = tlsTestPems();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Bad TLS')
        ->set('host', 'pg.internal')
        ->set('database', 'app')
        ->set('username', 'reader')
        ->set('tlsMode', 'verify-ca')
        ->call('save')
        ->assertHasErrors(['tlsCa'])
        ->set('tlsMode', 'require')
        ->set('tlsCert', $pems['cert'])
        ->call('save')
        ->assertHasErrors(['tlsKey']);

    expect(Connection::where('name', 'Bad TLS')->exists())->toBeFalse();
});

test('pasted PEM text is validated', function () {
    $dba = tlsDbaUser();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Bad PEM')
        ->set('host', 'pg.internal')
        ->set('database', 'app')
        ->set('username', 'reader')
        ->set('tlsCa', 'not a certificate')
        ->set('tlsKey', tlsTestPems()['cert'])
        ->call('save')
        ->assertHasErrors(['tlsCa', 'tlsKey']);
});

test('editing keeps stored PEMs on a blank box, replaces them on input and removes them on request', function () {
    $dba = tlsDbaUser();
    $pems = tlsTestPems();
    $team = $dba->currentTeam();
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->setTlsPem('ca', $pems['cert']);
    $connection->save();

    $component = Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->assertSet('tlsMode', 'require')
        ->assertSet('tlsCa', '')
        ->assertSee('stored')
        ->set('tlsMode', 'verify-ca')
        ->call('save')
        ->assertHasNoErrors();

    $connection->refresh();
    expect($connection->tlsMode())->toBe('verify-ca')
        ->and($connection->hasTlsPem('ca'))->toBeTrue();

    $component->call('openEdit', $connection->id)
        ->set('tlsMode', 'require')
        ->set('tlsClearCa', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($connection->fresh()->hasTlsPem('ca'))->toBeFalse();
});

test('editing a legacy connection shows its flag and saving does not silently change it', function () {
    $dba = tlsDbaUser();
    $legacy = Connection::factory()->create(['team_id' => $dba->currentTeam()->id, 'driver' => 'mysql', 'options' => ['tls' => ['mode' => null, 'flag' => 'driver_default']]]);

    Livewire::actingAs($dba)->test(Index::class)
        ->assertSee('TLS: driver default')
        ->call('openEdit', $legacy->id)
        ->assertSet('tlsMode', 'default')
        ->call('save')
        ->assertHasNoErrors();

    $tls = $legacy->fresh()->tlsSettings();

    expect($tls['mode'])->toBeNull()
        ->and($tls['flag'])->toBe('driver_default')
        ->and($legacy->fresh()->tlsState())->toBe(Connection::TLS_DRIVER_DEFAULT);
});

test('the connection list flags connections that do not enforce TLS', function () {
    $dba = tlsDbaUser();
    $teamId = $dba->currentTeam()->id;
    Connection::factory()->create(['team_id' => $teamId, 'name' => 'a-prefer', 'options' => ['tls' => ['mode' => 'prefer', 'flag' => 'not_enforced']]]);
    Connection::factory()->create(['team_id' => $teamId, 'name' => 'b-require']);

    Livewire::actingAs($dba)->test(Index::class)
        ->assertSee('TLS not enforced')
        ->assertSee('TLS required');
});

test('deleting a connection through the page removes its PEM files', function () {
    $dba = tlsDbaUser();
    $connection = Connection::factory()->create(['team_id' => $dba->currentTeam()->id]);
    $connection->setTlsPem('ca', tlsTestPems()['cert']);
    $connection->save();
    app(TlsPemStore::class)->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);

    expect(is_dir($directory))->toBeTrue();

    Livewire::actingAs($dba)->test(Index::class)->call('deleteConnection', $connection->id);

    expect(file_exists($directory))->toBeFalse();
});

test('deleting a team removes the PEM files of its connections, through the page and through the model', function (string $path) {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $store = app(TlsPemStore::class);

    $make = function (Team $owner) use ($store): string {
        $connection = Connection::factory()->create(['team_id' => $owner->id]);
        $connection->setTlsPem('ca', tlsTestPems()['cert']);
        $connection->setTlsPem('key', tlsTestPems()['key']);
        $connection->save();
        $store->sync($connection);

        return storage_path('app/tls/'.$connection->id);
    };

    $doomedA = $make($team);
    $doomedB = $make($team);
    $kept = $make($other);

    expect(is_dir($doomedA))->toBeTrue()->and(is_dir($doomedB))->toBeTrue();

    if ($path === 'page') {
        Livewire::actingAs($admin)->test(Teams::class)->call('deleteTeam', $team->id);
    } else {
        $team->delete();
    }

    expect(Team::find($team->id))->toBeNull()
        ->and(file_exists($doomedA))->toBeFalse()
        ->and(file_exists($doomedB))->toBeFalse()
        ->and(is_dir($kept))->toBeTrue();
})->with(['page', 'model']);

test('saving a connection drops PEM files it no longer needs', function () {
    $dba = tlsDbaUser();
    $connection = Connection::factory()->create(['team_id' => $dba->currentTeam()->id, 'driver' => 'mysql']);
    $connection->setTlsPem('ca', tlsTestPems()['cert']);
    $connection->setTlsPem('cert', tlsTestPems()['cert']);
    $connection->setTlsPem('key', tlsTestPems()['key']);
    $connection->save();
    app(TlsPemStore::class)->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);

    expect(file_exists($directory.'/key.pem'))->toBeTrue();

    // Removing one stored PEM while TLS stays required deletes just that file.
    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('tlsClearCert', true)
        ->set('tlsClearKey', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(file_exists($directory.'/cert.pem'))->toBeFalse()
        ->and(file_exists($directory.'/key.pem'))->toBeFalse()
        ->and(file_exists($directory.'/ca.pem'))->toBeTrue();

    // Leaving TLS to the driver default means the files are not used any more.
    Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('tlsMode', 'default')
        ->call('save')
        ->assertHasNoErrors();

    expect(file_exists($directory))->toBeFalse()
        ->and($connection->fresh()->hasTlsPem('ca'))->toBeTrue();
});

test('changing the driver to one that takes no PEM files removes the files', function (string $driver) {
    $dba = tlsDbaUser();
    $connection = Connection::factory()->create(['team_id' => $dba->currentTeam()->id, 'driver' => 'pgsql']);
    $connection->setTlsPem('ca', tlsTestPems()['cert']);
    $connection->save();
    app(TlsPemStore::class)->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);

    expect(is_dir($directory))->toBeTrue();

    $component = Livewire::actingAs($dba)->test(Index::class)
        ->call('openEdit', $connection->id)
        ->set('driver', $driver);

    if ($driver === 'sqlite') {
        $component->set('host', '')->set('username', '')->set('database', '/var/db/queryproxy-tls-test.sqlite');
    }

    $component->call('save')->assertHasNoErrors();

    expect(file_exists($directory))->toBeFalse()
        ->and($connection->fresh()->hasTlsPem('ca'))->toBeFalse();
})->with(['sqlsrv', 'sqlite']);

test('a stored PEM that cannot be decrypted is reported instead of being treated as absent', function () {
    $connection = Connection::factory()->create();
    $connection->setTlsPem('key', tlsTestPems()['key']);
    $connection->save();

    $tls = $connection->tlsSettings();
    $tls['pem']['key'] = 'not-a-valid-payload';
    $connection->options = ['tls' => $tls];
    $connection->save();

    $store = app(TlsPemStore::class);

    expect($connection->hasTlsPem('key'))->toBeTrue()
        ->and(fn () => $store->sync($connection))->toThrow(RuntimeException::class, 'cannot be decrypted');

    // Removing the broken value never needs to decrypt it.
    $connection->setTlsPem('key', null);
    $connection->save();

    expect($store->sync($connection))->toBe([]);
});

test('purge also removes dot-files left by an interrupted write and never throws', function () {
    $connection = Connection::factory()->create();
    $connection->setTlsPem('ca', tlsTestPems()['cert']);
    $connection->save();
    $store = app(TlsPemStore::class);
    $store->sync($connection);
    $directory = storage_path('app/tls/'.$connection->id);
    file_put_contents($directory.'/.pemAbC123', 'half-written key');

    $connection->delete();

    expect(file_exists($directory))->toBeFalse();

    // A second purge for a directory that is already gone is a no-op.
    $store->purgeById($connection->id);

    expect(file_exists($directory))->toBeFalse();
});

test('the verification checkbox updates the server-not-verified notice live', function () {
    $dba = tlsDbaUser();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('driver', 'mysql')
        ->assertSeeHtml('wire:model.live="tlsVerifyServerCert"')
        ->assertSee('the server is not verified')
        ->set('tlsVerifyServerCert', true)
        ->assertDontSee('the server is not verified');
});

test('the test result on the page shows the TLS state', function () {
    $dba = tlsDbaUser();
    $connection = Connection::factory()->create(['team_id' => $dba->currentTeam()->id, 'host' => '127.0.0.1', 'port' => '1']);

    Livewire::actingAs($dba)->test(Index::class)
        ->call('testConnection', $connection->id)
        ->assertSee('Connection failed')
        ->assertSee('required (require)');
});
