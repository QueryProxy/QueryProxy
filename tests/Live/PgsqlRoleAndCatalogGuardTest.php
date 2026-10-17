<?php

/*
 * Live PostgreSQL checks for the role, catalog, ALTER SYSTEM and READ WRITE
 * rules of SqlInspector (v0.2.6).
 *
 * Every test asserts the guard's verdict first and then runs the statement
 * directly on the server inside a transaction that is rolled back, to observe
 * the effect the rule exists for (the role really switches, the catalog row
 * really changes, the read-only transaction really turns read-write). ALTER
 * SYSTEM cannot run in a transaction, so its test undoes itself with ALTER
 * SYSTEM RESET. The tests that need privileges skip on a non-superuser. Runs only when QUERYPROXY_LIVE_PGSQL_HOST is set (group "live-pgsql"); see
 * PgsqlGuardTest.php for the environment.
 */

use Tests\Live\LivePgsql;

beforeEach(function () {
    if (! LivePgsql::configured()) {
        $this->markTestSkipped('Set '.LivePgsql::ENV_PREFIX.'HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run the live PostgreSQL guard tests.');
    }

    $this->live = LivePgsql::start();
});

afterEach(function () {
    if (isset($this->live)) {
        $this->live->stop();
    }
});

const LIVE_ROLE = 'qp_live_role';

function requireSuperuser(LivePgsql $live): void
{
    if (! $live->isSuperuser()) {
        test()->markTestSkipped('The live PostgreSQL role is not a superuser.');
    }
}

function expectRefused(LivePgsql $live, string $sql, string $needle): void
{
    $result = $live->guard($sql);

    expect($result->passes())->toBeFalse("the guard let through: {$sql}")
        ->and(implode("\n", $result->violations))->toContain($needle);
}

function currentUser(LivePgsql $live): string
{
    return (string) $live->db()->selectOne('SELECT current_user AS name')->name;
}

/** The number of work_mem entries in postgresql.auto.conf, as the server parsed it. */
function autoConfWorkMem(LivePgsql $live): ?string
{
    $row = $live->db()->selectOne(
        "SELECT setting FROM pg_file_settings WHERE name = 'work_mem' AND sourcefile LIKE '%postgresql.auto.conf'"
    );

    return $row === null ? null : (string) $row->setting;
}

it('refuses ALTER SYSTEM and the server really would have written it', function (string $sql) {
    requireSuperuser($this->live);

    if (autoConfWorkMem($this->live) !== null) {
        $this->markTestSkipped('postgresql.auto.conf already holds a work_mem entry.');
    }

    expectRefused($this->live, $sql, 'ALTER SYSTEM');
    expect(autoConfWorkMem($this->live))->toBeNull();

    // The witness: the same statement sent straight to the server is persisted.
    try {
        $this->live->db()->unprepared("ALTER SYSTEM SET work_mem = '123MB'");

        expect(autoConfWorkMem($this->live))->toBe('123MB');
    } finally {
        $this->live->db()->unprepared('ALTER SYSTEM RESET work_mem');
    }

    expect(autoConfWorkMem($this->live))->toBeNull();
})->with([
    'set' => "ALTER SYSTEM SET work_mem = '1GB'",
    'reset' => 'ALTER SYSTEM RESET work_mem',
    'reset all' => 'ALTER SYSTEM RESET ALL',
    'lower case with a comment' => "alter /* x */ system set work_mem = '1GB'",
]);

it('refuses a role switch that really changes the session identity', function (string $template, string $needle) {
    requireSuperuser($this->live);

    $sql = str_replace('{role}', LIVE_ROLE, $template);
    expectRefused($this->live, $sql, $needle);

    $db = $this->live->db();
    $original = currentUser($this->live);

    $db->beginTransaction();

    try {
        $db->unprepared('CREATE ROLE '.LIVE_ROLE.' NOLOGIN');
        $db->unprepared($sql);

        expect(currentUser($this->live))->toBe(LIVE_ROLE);
    } finally {
        $db->rollBack();
    }

    expect(currentUser($this->live))->toBe($original);
})->with([
    'SET ROLE' => ['SET ROLE {role}', 'SET ROLE'],
    'SET LOCAL ROLE' => ['SET LOCAL ROLE {role}', 'SET ROLE'],
    'SET SESSION AUTHORIZATION' => ['SET SESSION AUTHORIZATION {role}', 'SESSION AUTHORIZATION'],
    'set_config role' => ["SELECT set_config('role', '{role}', false)", 'role'],
    'set_config session_authorization' => ["SELECT set_config('session_authorization', '{role}', false)", 'session_authorization'],
    'SET ROLE in a DO block' => ['DO $$ BEGIN SET ROLE {role}; END $$', 'SET ROLE'],
]);

it('refuses every spelling of RESET ROLE that would drop a role restriction', function (string $sql, string $needle) {
    requireSuperuser($this->live);

    expectRefused($this->live, $sql, $needle);

    $db = $this->live->db();
    $original = currentUser($this->live);

    $db->beginTransaction();

    try {
        $db->unprepared('CREATE ROLE '.LIVE_ROLE.' NOLOGIN');
        $db->unprepared('SET ROLE '.LIVE_ROLE);
        expect(currentUser($this->live))->toBe(LIVE_ROLE);

        $db->unprepared($sql);

        expect(currentUser($this->live))->toBe($original);
    } finally {
        $db->rollBack();
    }
})->with([
    'RESET ROLE' => ['RESET ROLE', 'RESET ROLE'],
    'RESET role lower case' => ['reset role', 'RESET ROLE'],
    'RESET with a quoted role' => ['RESET "role"', 'RESET ROLE'],
    'RESET with a quoted ROLE and a comment' => ['RESET /* x */ "role"', 'RESET ROLE'],
    'RESET SESSION AUTHORIZATION' => ['RESET SESSION AUTHORIZATION', 'SESSION AUTHORIZATION'],
    'RESET ROLE in a DO block' => ['DO $$ BEGIN RESET "role"; END $$', 'RESET ROLE'],
]);

it('refuses a write to a system catalog however it is reached, and the server really applies it', function (string $template, string $witness, string $expected) {
    requireSuperuser($this->live);

    $schema = $this->live->schema;
    $function = "{$schema}.qp_live_fn";
    $match = "proname = 'qp_live_fn' AND pronamespace = '{$schema}'::regnamespace";
    $sql = str_replace(['{match}', '{schema}'], [$match, $schema], $template);

    expectRefused($this->live, $sql, 'system catalog');

    $db = $this->live->db();
    $db->beginTransaction();

    try {
        $db->unprepared("CREATE FUNCTION {$function}() RETURNS int LANGUAGE sql AS 'SELECT 1'");
        $db->unprepared($sql);

        expect((string) $db->selectOne(str_replace('{match}', $match, $witness))->value)->toBe($expected);
    } finally {
        $db->rollBack();
    }
})->with([
    'UPDATE' => [
        "UPDATE pg_catalog.pg_proc SET prosrc = 'SELECT 2' WHERE {match}",
        'SELECT prosrc AS value FROM pg_proc WHERE {match}',
        'SELECT 2',
    ],
    'INSERT' => [
        "INSERT INTO pg_catalog.pg_description (objoid, classoid, objsubid, description) SELECT oid, 'pg_proc'::regclass, 0, 'qp live' FROM pg_proc WHERE {match}",
        "SELECT obj_description(oid, 'pg_proc') AS value FROM pg_proc WHERE {match}",
        'qp live',
    ],
    'INSERT inside a CTE' => [
        "WITH ins AS (INSERT INTO pg_catalog.pg_description (objoid, classoid, objsubid, description) SELECT oid, 'pg_proc'::regclass, 0, 'qp live' FROM pg_proc WHERE {match} RETURNING objoid) SELECT count(*) FROM ins",
        "SELECT obj_description(oid, 'pg_proc') AS value FROM pg_proc WHERE {match}",
        'qp live',
    ],
    'UPDATE inside a CTE' => [
        "WITH upd AS (UPDATE pg_catalog.pg_proc SET prosrc = 'SELECT 2' WHERE {match} RETURNING oid) SELECT count(*) FROM upd",
        'SELECT prosrc AS value FROM pg_proc WHERE {match}',
        'SELECT 2',
    ],
]);

it('lets a system catalog be read', function () {
    expect($this->live->guard('SELECT * FROM pg_proc LIMIT 1')->violations)->toBe([])
        ->and($this->live->guard('SELECT rolname FROM pg_roles LIMIT 1')->violations)->toBe([])
        ->and($this->live->guard('WITH p AS (SELECT oid FROM pg_proc LIMIT 1) SELECT * FROM p')->violations)->toBe([]);

    expect($this->live->db()->select('SELECT proname FROM pg_proc LIMIT 1'))->not->toBeEmpty();
});

it('refuses READ WRITE, which really lifts a read-only transaction, and keeps READ ONLY', function () {
    $insert = "INSERT INTO items (id, label) VALUES (6, 'six')";
    $escape = 'SET TRANSACTION READ WRITE';

    foreach ([$escape, 'START TRANSACTION READ WRITE', 'BEGIN READ WRITE', 'SET SESSION CHARACTERISTICS AS TRANSACTION READ WRITE'] as $sql) {
        expectRefused($this->live, $sql, 'READ WRITE');
    }

    expect($this->live->guard('SET TRANSACTION READ ONLY')->violations)->toBe([]);

    $db = $this->live->db();
    $db->beginTransaction();

    // unprepared() on purpose: see PgsqlGuardTest.php (a deallocation takes a
    // snapshot and PostgreSQL then refuses the switch back to read-write).
    try {
        $db->unprepared('SET TRANSACTION READ ONLY');
        $db->unprepared($escape);
        $db->statement($insert);

        expect($this->live->label(6))->toBe('six');
    } finally {
        $db->rollBack();
    }
});
