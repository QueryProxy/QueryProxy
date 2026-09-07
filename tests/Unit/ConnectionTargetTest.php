<?php

use App\Models\Connection;
use App\Services\Connections\DynamicConnectionFactory;

test('a sqlite connection pointed at the app directory is rejected', function () {
    $factory = app(DynamicConnectionFactory::class);

    $connection = new Connection([
        'driver' => 'sqlite',
        'database' => database_path('database.sqlite'),
    ]);

    expect($factory->targetViolations($connection))->not->toBeEmpty();
});

test('a sqlite connection outside the app directory is allowed', function () {
    $factory = app(DynamicConnectionFactory::class);

    $connection = new Connection([
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/external-target.sqlite',
    ]);

    expect($factory->targetViolations($connection))->toBeEmpty();
});

test('a host on the denylist is rejected', function () {
    config(['queryproxy.connection_host_denylist' => ['internal.db']]);
    $factory = app(DynamicConnectionFactory::class);

    $connection = new Connection([
        'driver' => 'pgsql',
        'host' => 'internal.db',
        'port' => 5432,
        'database' => 'x',
        'username' => 'u',
    ]);

    expect($factory->targetViolations($connection))->not->toBeEmpty();
});

test('driver errors are redacted of host, username and database', function () {
    $factory = app(DynamicConnectionFactory::class);

    $connection = new Connection([
        'driver' => 'pgsql',
        'host' => '10.0.2.15',
        'database' => 'appdb',
        'username' => 'app_admin',
        'password' => 'hunter2',
    ]);

    $raw = 'connection to server at "10.0.2.15" failed: password authentication failed for user "app_admin"';
    $redacted = $factory->redactError($raw, $connection);

    expect($redacted)->not->toContain('10.0.2.15')
        ->and($redacted)->not->toContain('app_admin')
        ->and($redacted)->toContain('[redacted]');
});
