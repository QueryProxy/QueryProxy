<?php

use App\Enums\StatementType;
use App\Services\Sql\InspectionResult;
use App\Services\Sql\SqlInspector;

function inspect(string $sql): InspectionResult
{
    return app(SqlInspector::class)->inspect($sql);
}

test('select without limit gets the default limit injected', function () {
    $result = inspect('SELECT * FROM users');

    expect($result->passes())->toBeTrue()
        ->and($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 1000')
        ->and($result->statements[0]->limitInjected)->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
});

test('select with a reasonable limit is untouched', function () {
    $result = inspect('SELECT * FROM users LIMIT 50');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 50')
        ->and($result->statements[0]->limitInjected)->toBeFalse()
        ->and($result->statements[0]->limitClamped)->toBeFalse();
});

test('select limit above the hard cap is clamped', function () {
    $result = inspect('SELECT * FROM users LIMIT 20000');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 10000')
        ->and($result->statements[0]->limitClamped)->toBeTrue();
});

test('mysql offset-comma limit clamps the count only', function () {
    $result = inspect('SELECT * FROM users LIMIT 10, 20000');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 10, 10000');
});

test('limit-offset form clamps the count only', function () {
    $result = inspect('SELECT * FROM users LIMIT 99999 OFFSET 4');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 10000 OFFSET 4');
});

test('subquery limits are ignored when guarding the outer query', function () {
    $result = inspect('SELECT * FROM (SELECT id FROM t LIMIT 5) q');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM (SELECT id FROM t LIMIT 5) q LIMIT 1000');
});

test('limit is injected before a locking clause', function () {
    $result = inspect('SELECT * FROM jobs FOR UPDATE');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM jobs LIMIT 1000 FOR UPDATE');
});

test('limit all is clamped to the hard cap', function () {
    $result = inspect('SELECT * FROM users LIMIT ALL');

    expect($result->statements[0]->preparedSql)->toBe('SELECT * FROM users LIMIT 10000');
});

test('update without where is rejected', function () {
    $result = inspect('UPDATE users SET active = 0');

    expect($result->passes())->toBeFalse()
        ->and($result->violations[0])->toContain('WHERE');
});

test('delete without where is rejected', function () {
    $result = inspect('DELETE FROM users');

    expect($result->passes())->toBeFalse()
        ->and($result->violations[0])->toContain('WHERE');
});

test('update with where passes and is classified as write', function () {
    $result = inspect('UPDATE users SET active = 0 WHERE id = 5');

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write)
        ->and($result->statements[0]->preparedSql)->toBe('UPDATE users SET active = 0 WHERE id = 5');
});

test('multiple statements without a transaction are rejected', function () {
    $result = inspect('UPDATE a SET x=1 WHERE id=1; UPDATE b SET y=2 WHERE id=2');

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->violations))->toContain('explicit transaction');
});

test('an explicit transaction with multiple writes passes', function () {
    $result = inspect("BEGIN;\nUPDATE a SET x=1 WHERE id=1;\nINSERT INTO logs(msg) VALUES ('x');\nCOMMIT;");

    expect($result->passes())->toBeTrue()
        ->and($result->isTransaction)->toBeTrue()
        ->and($result->statements)->toHaveCount(2)
        ->and($result->type())->toBe(StatementType::Write);
});

test('where guard applies inside transactions', function () {
    $result = inspect('BEGIN; DELETE FROM users; COMMIT;');

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->violations))->toContain('WHERE');
});

test('begin without commit is rejected', function () {
    $result = inspect('BEGIN; UPDATE a SET x=1 WHERE id=1;');

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->violations))->toContain('COMMIT');
});

test('rollback statements are rejected', function () {
    $result = inspect('BEGIN; UPDATE a SET x=1 WHERE id=1; ROLLBACK;');

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->violations))->toContain('ROLLBACK');
});

test('forbidden administrative statements are rejected', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'DROP DATABASE prod',
    'GRANT ALL ON *.* TO joe',
    'REVOKE SELECT ON db.t FROM joe',
    'CREATE USER hacker IDENTIFIED BY "x"',
    'SET GLOBAL max_connections = 1',
]);

test('show and explain are classified as reads without limit injection', function () {
    expect(inspect('SHOW TABLES')->type())->toBe(StatementType::Read)
        ->and(inspect('SHOW TABLES')->statements[0]->preparedSql)->toBe('SHOW TABLES')
        ->and(inspect('EXPLAIN SELECT * FROM t')->type())->toBe(StatementType::Read);
});

test('a cte wrapping a write is classified as write', function () {
    $result = inspect('WITH doomed AS (SELECT id FROM t) DELETE FROM t WHERE id IN (SELECT id FROM doomed)');

    expect($result->type())->toBe(StatementType::Write);
});

test('a pure cte select is read and gets a limit', function () {
    $result = inspect('WITH recent AS (SELECT * FROM t) SELECT * FROM recent');

    expect($result->type())->toBe(StatementType::Read)
        ->and($result->statements[0]->preparedSql)->toEndWith('LIMIT 1000');
});

test('unparseable dialect-specific selects still get guarded best-effort', function () {
    $result = inspect("SELECT id::text FROM users WHERE meta @> '{}'");

    expect($result->type())->toBe(StatementType::Read)
        ->and($result->statements[0]->preparedSql)->toEndWith('LIMIT 1000');
});

test('unparseable update without where is still rejected', function () {
    $result = inspect('UPDATE users SET meta = meta || \'{"a":1}\'::jsonb');

    expect($result->passes())->toBeFalse();
});

test('empty and comment-only input is rejected', function () {
    expect(inspect('')->passes())->toBeFalse()
        ->and(inspect('-- just a comment')->passes())->toBeFalse()
        ->and(inspect('/* block */')->passes())->toBeFalse();
});

test('insert is a write and passes without where', function () {
    $result = inspect('INSERT INTO t (a) VALUES (1)');

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write);
});

test('semicolons inside string literals do not split statements', function () {
    $result = inspect("SELECT * FROM t WHERE name = 'a;b'");

    expect($result->passes())->toBeTrue()
        ->and($result->statements)->toHaveCount(1);
});

test('trailing semicolon does not create a phantom statement', function () {
    $result = inspect('SELECT * FROM t;');

    expect($result->statements)->toHaveCount(1)
        ->and($result->passes())->toBeTrue();
});
