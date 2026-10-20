<?php

use App\Models\Connection;
use App\Services\Connections\DynamicConnectionFactory;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

test('a connection has read credentials only with a non-blank read username', function (?string $username, bool $expected) {
    expect((new Connection(['read_username' => $username]))->hasReadCredentials())->toBe($expected);
})->with([
    'set' => ['reader', true],
    'null' => [null, false],
    'empty' => ['', false],
    'blank' => ['   ', false],
]);

test('read credentials are encrypted at rest', function () {
    $connection = Connection::factory()->create(['read_username' => 'reader-user', 'read_password' => 'reader-secret']);

    $raw = DB::table('connections')->where('id', $connection->id)->first();

    expect($raw->read_username)->not->toContain('reader-user')
        ->and($raw->read_password)->not->toContain('reader-secret')
        ->and($connection->fresh()->read_username)->toBe('reader-user')
        ->and($connection->fresh()->read_password)->toBe('reader-secret');
});

test('the read connection logs in with the read identity and the main one with the main identity', function () {
    $factory = app(DynamicConnectionFactory::class);
    $connection = Connection::factory()->create([
        'username' => 'writer', 'password' => 'w-secret',
        'read_username' => 'reader', 'read_password' => 'r-secret',
    ]);

    $main = $factory->configFor($connection);
    $read = $factory->configFor($connection, readIdentity: true);

    expect($main['username'])->toBe('writer')
        ->and($main['password'])->toBe('w-secret')
        ->and($read['username'])->toBe('reader')
        ->and($read['password'])->toBe('r-secret')
        ->and($read['host'])->toBe($main['host']);

    $name = $factory->configureRead($connection);

    expect($name)->toBe('queryproxy_target_'.$connection->id.'_read')
        ->and(config("database.connections.{$name}.username"))->toBe('reader');

    $factory->purge($connection);

    expect(config("database.connections.{$name}"))->toBeNull();
});

test('a read identity without a password connects with an empty password', function () {
    $connection = Connection::factory()->create(['read_username' => 'reader', 'read_password' => null]);

    expect(app(DynamicConnectionFactory::class)->configFor($connection, readIdentity: true)['password'])->toBe('');
});

test('the target connection config carries the driver specific timeout options', function () {
    config(['queryproxy.execution_timeout' => 65, 'queryproxy.lock_timeout' => 7]);
    $factory = app(DynamicConnectionFactory::class);

    expect($factory->configFor(Connection::factory()->make(['driver' => 'sqlsrv']))['options'])->toBe([1001 => 60])
        ->and($factory->configFor(Connection::factory()->sqlite('/tmp/x.sqlite')->make())['options'])->toBe([PDO::ATTR_TIMEOUT => 7])
        ->and($factory->configFor(Connection::factory()->make(['driver' => 'pgsql'])))->not->toHaveKey('options');
});

test('error messages are stripped of the read identity too', function () {
    $connection = new Connection([
        'host' => 'db.internal', 'username' => 'writer', 'password' => 'w-secret',
        'read_username' => 'reader-user', 'read_password' => 'reader-secret',
    ]);

    $message = app(DynamicConnectionFactory::class)
        ->redactError('login failed for reader-user with reader-secret on db.internal', $connection);

    expect($message)->toBe('login failed for [redacted] with [redacted] on [redacted]');
});

test('testing a connection also probes the read identity and says which one failed', function () {
    DB::extend('pgsql', function (array $config, string $name) {
        if (str_ends_with($name, '_read')) {
            throw new RuntimeException('password authentication failed for user "'.$config['username'].'"');
        }

        return new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', $config + ['name' => $name]);
    });

    $factory = app(DynamicConnectionFactory::class);
    $connection = Connection::factory()->create(['driver' => 'pgsql', 'read_username' => 'reader-user', 'read_password' => 'x']);

    $result = $factory->test($connection);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toStartWith('Read credentials: ')
        ->and($result['error'])->not->toContain('reader-user');

    $withoutRead = Connection::factory()->create(['driver' => 'pgsql']);

    expect($factory->test($withoutRead)['ok'])->toBeTrue();
});
