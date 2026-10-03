<?php

use Tests\Live\LivePgsql;

/*
 * The libpq connection string the live dblink test hands to the server. The
 * live group itself needs a database; these checks only need the environment.
 */

beforeEach(function () {
    $this->savedLiveEnv = [];

    foreach (['HOST', 'PORT', 'SERVER_HOST', 'SERVER_PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
        $this->savedLiveEnv[$key] = getenv(LivePgsql::ENV_PREFIX.$key);
        putenv(LivePgsql::ENV_PREFIX.$key);
    }
});

afterEach(function () {
    foreach ($this->savedLiveEnv as $key => $value) {
        putenv($value === false ? LivePgsql::ENV_PREFIX.$key : LivePgsql::ENV_PREFIX.$key.'='.$value);
    }
});

function setLivePgsqlEnv(array $values): void
{
    foreach ($values as $key => $value) {
        putenv(LivePgsql::ENV_PREFIX.$key.'='.$value);
    }
}

test('values are single-quoted with backslashes and quotes escaped', function () {
    expect(LivePgsql::connectionString([
        'dbname' => 'qp',
        'password' => "it's a \\ secret = yes",
    ]))->toBe("dbname='qp' password='it\\'s a \\\\ secret = yes'");
});

test('empty values are left out', function () {
    expect(LivePgsql::connectionString(['host' => '', 'dbname' => 'qp', 'password' => '']))
        ->toBe("dbname='qp'");
});

test('without host, port or password the string falls back to the local socket', function () {
    setLivePgsqlEnv(['DATABASE' => 'queryproxy_test', 'USERNAME' => 'queryproxy_test']);

    expect(LivePgsql::serverSideConnectionString())
        ->toBe("dbname='queryproxy_test' user='queryproxy_test'");
});

test('host, port and password are passed on when configured', function () {
    setLivePgsqlEnv([
        'HOST' => '127.0.0.1',
        'PORT' => '5432',
        'DATABASE' => 'queryproxy_test',
        'USERNAME' => "o'neil",
        'PASSWORD' => 'p\\w',
    ]);

    expect(LivePgsql::serverSideConnectionString())
        ->toBe("host='127.0.0.1' port='5432' dbname='queryproxy_test' user='o\\'neil' password='p\\\\w'");
});

test('the server-side host and port override the ones the tests connect to', function () {
    setLivePgsqlEnv([
        'HOST' => '127.0.0.1',
        'PORT' => '55432',
        'SERVER_HOST' => 'localhost',
        'SERVER_PORT' => '5432',
        'DATABASE' => 'queryproxy_test',
        'USERNAME' => 'queryproxy_test',
        'PASSWORD' => 'queryproxy_test',
    ]);

    expect(LivePgsql::serverSideConnectionString())
        ->toBe("host='localhost' port='5432' dbname='queryproxy_test' user='queryproxy_test' password='queryproxy_test'");
});
