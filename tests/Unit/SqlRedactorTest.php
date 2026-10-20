<?php

use App\Enums\DbDriver;
use App\Services\Sql\SqlRedactor;

function redact(string $sql, ?DbDriver $driver = null): ?string
{
    return (new SqlRedactor)->redact($sql, $driver);
}

// --- Recognised literal types --------------------------------------------

test('redactor replaces single-quoted strings and numbers', function () {
    expect(redact("SELECT * FROM users WHERE email = 'ada@example.com' AND age > 30 LIMIT 10", DbDriver::Pgsql))
        ->toBe('SELECT * FROM users WHERE email = ? AND age > ? LIMIT ?');
});

test('redactor handles doubled quotes inside a string', function () {
    expect(redact("SELECT 'it''s a secret', 1", DbDriver::Pgsql))->toBe('SELECT ?, ?');
});

test('redactor recognises string prefixes and charset introducers', function () {
    expect(redact("SELECT N'a', E'b', X'4142', B'101', U&'d\\0061t', _utf8mb4'c'", DbDriver::Mysql))
        ->toBe('SELECT ?, ?, ?, ?, ?, ?');
});

test('redactor recognises hex, binary, decimal, fraction, exponent and underscored numbers', function () {
    expect(redact('SELECT 0x1F, 0b101, 3.14, .5, 1e10, 2.5E-3, 1_000_000, -42', DbDriver::Pgsql))
        ->toBe('SELECT ?, ?, ?, ?, ?, ?, ?, -?');
});

test('redactor recognises PostgreSQL dollar-quoted strings', function () {
    expect(redact('SELECT $$top secret$$, $tag$ it\'s $$ inside $tag$ FROM t', DbDriver::Pgsql))
        ->toBe('SELECT ?, ? FROM t');
});

test('redactor treats double quotes by driver', function () {
    expect(redact('SELECT "name" FROM "Users" WHERE x = "secret"', DbDriver::Mysql))
        ->toBe('SELECT ? FROM ? WHERE x = ?')
        ->and(redact('SELECT "name" FROM "Users" WHERE x = \'secret\'', DbDriver::Pgsql))
        ->toBe('SELECT "name" FROM "Users" WHERE x = ?')
        ->and(redact('SELECT "name" FROM "Users"', DbDriver::Sqlsrv))
        ->toBe('SELECT "name" FROM "Users"');
});

test('redactor honours backslash escapes only where the driver has them', function () {
    // MySQL: `\'` is an escaped quote, so the string runs to the last quote.
    expect(redact("SELECT 'a\\' OR 1=1 --' FROM t", DbDriver::Mysql))->toBe('SELECT ? FROM t')
        // PostgreSQL / SQLite: `\` is an ordinary character, the string ends at `\'`.
        ->and(redact("SELECT 'a\\' FROM t WHERE x = 5", DbDriver::Pgsql))->toBe('SELECT ? FROM t WHERE x = ?')
        ->and(redact("SELECT 'a\\' FROM t WHERE x = 5", DbDriver::Sqlite))->toBe('SELECT ? FROM t WHERE x = ?')
        // PostgreSQL E'' strings do escape.
        ->and(redact("SELECT E'a\\'b' FROM t", DbDriver::Pgsql))->toBe('SELECT ? FROM t');
});

test('redactor keeps identifiers, keywords, parameters and variables', function () {
    expect(redact('SELECT `col1`, [odd name], t.col2 FROM `my table` t WHERE a IS NULL AND b = TRUE AND c = :named AND d = ? AND e = $1 AND f = @var', DbDriver::Mysql))
        ->toBe('SELECT `col1`, [odd name], t.col2 FROM `my table` t WHERE a IS NULL AND b = TRUE AND c = :named AND d = ? AND e = $1 AND f = @var')
        ->and(redact('SELECT [odd name], [x]]y] FROM t WHERE id = 7', DbDriver::Sqlsrv))
        ->toBe('SELECT [odd name], [x]]y] FROM t WHERE id = ?');
});

test('redactor does not touch digits inside identifiers', function () {
    expect(redact('SELECT col1, t2.col_3 FROM table4 WHERE col1 = 5', DbDriver::Pgsql))
        ->toBe('SELECT col1, t2.col_3 FROM table4 WHERE col1 = ?');
});

test('redactor drops comments, which can hold values', function () {
    expect(redact("SELECT 1 -- password is hunter2\nFROM t /* token abc123 */ WHERE x = 2", DbDriver::Pgsql))
        ->toBe("SELECT ? \nFROM t   WHERE x = ?")
        ->and(redact("SELECT 1 # secret\nFROM t", DbDriver::Mysql))->toBe("SELECT ? \nFROM t");
});

test('redactor reads MySQL double dash without a space as two minus signs', function () {
    expect(redact('SELECT 5--3', DbDriver::Mysql))->toBe('SELECT ?--?');
});

test('redactor nests PostgreSQL block comments but not the others', function () {
    expect(redact('SELECT /* a /* b */ still comment */ 1', DbDriver::Pgsql))->toBe('SELECT   ?')
        ->and(redact('SELECT /* a /* b */ 1', DbDriver::Mysql))->toBe('SELECT   ?');
});

test('redactor leaves SQL without literals alone', function () {
    expect(redact('SELECT id, name FROM users ORDER BY name', DbDriver::Pgsql))
        ->toBe('SELECT id, name FROM users ORDER BY name')
        ->and(redact('', DbDriver::Pgsql))->toBe('');
});

test('redactor keeps multibyte identifiers and redacts multibyte strings', function () {
    expect(redact("SELECT müşteri FROM sipariş WHERE ad = 'Çağrı' AND no = 12", DbDriver::Pgsql))
        ->toBe('SELECT müşteri FROM sipariş WHERE ad = ? AND no = ?');
});

test('redactor with an unknown driver takes the conservative reading', function () {
    expect(redact('SELECT "maybe secret", \'x\', 5', null))->toBe('SELECT ?, ?, ?')
        ->and(redact('SELECT $$a$$ FROM t', null))->toBe('SELECT ? FROM t');
});

// --- Cannot parse: fail closed -------------------------------------------

test('redactor returns null for unterminated strings, identifiers, comments and dollar quotes', function (string $sql, ?DbDriver $driver) {
    expect(redact($sql, $driver))->toBeNull();
})->with([
    'unterminated string' => ["SELECT 'oops", DbDriver::Pgsql],
    'unterminated string, backslash eats the quote' => ["SELECT 'oops\\'", DbDriver::Mysql],
    'unterminated double-quoted identifier' => ['SELECT "oops', DbDriver::Pgsql],
    'unterminated backtick identifier' => ['SELECT `oops', DbDriver::Mysql],
    'unterminated bracket identifier' => ['SELECT [oops', DbDriver::Sqlsrv],
    'unterminated block comment' => ['SELECT 1 /* oops', DbDriver::Pgsql],
    'unterminated dollar quote' => ['SELECT $tag$ oops $other$', DbDriver::Pgsql],
    'unterminated N string' => ["SELECT N'oops", DbDriver::Sqlsrv],
]);

test('redactor returns null for MySQL executable comments', function () {
    expect(redact('SELECT /*!50000 1 */', DbDriver::Mysql))->toBeNull()
        ->and(redact('SELECT /*M!100100 1 */', DbDriver::Mariadb))->toBeNull();
});

test('redactor returns null for invalid UTF-8', function () {
    expect(redact("SELECT '\xB1\x31'", DbDriver::Pgsql))->toBeNull();
});

test('redactor returns null when the string end depends on the unknown driver', function () {
    // With backslash as an escape this string never ends; without it, it ends early.
    expect(redact("SELECT 'a\\' , 'b'", null))->toBeNull()
        // Same text is fine once the driver settles the question.
        ->and(redact("SELECT 'a\\' , 'b'", DbDriver::Pgsql))->toBe('SELECT ? , ?')
        ->and(redact('SELECT /* a /* b */ 1', null))->toBeNull();
});

test('redactor never falls back to the original text on failure', function () {
    $secret = "SELECT * FROM t WHERE token = 'hunter2";

    expect(redact($secret, DbDriver::Pgsql))->toBeNull();
});

// --- Parses, literal type not recognised: stays visible -------------------

test('redactor leaves a money literal visible where it cannot tell it from a positional parameter', function () {
    // Documented behaviour (ADR-009 amendment 2026-10-20): parsed, literal type unrecognised -> visible.
    expect(redact('SELECT * FROM t WHERE price > $12.50', DbDriver::Pgsql))->toBe('SELECT * FROM t WHERE price > $12.50')
        ->and(redact('SELECT * FROM t WHERE price > $12.50', null))->toBe('SELECT * FROM t WHERE price > $12.50')
        ->and(redact('SELECT * FROM t WHERE id = $1', DbDriver::Pgsql))->toBe('SELECT * FROM t WHERE id = $1');
});

test('redactor does recognise the money literal on SQL Server', function () {
    expect(redact('SELECT * FROM t WHERE price > $12.50', DbDriver::Sqlsrv))->toBe('SELECT * FROM t WHERE price > ?');
});

// --- SQL Server: SET QUOTED_IDENTIFIER OFF makes "..." a string ------------

test('redactor treats double quotes as strings on SQL Server after SET QUOTED_IDENTIFIER OFF', function () {
    $sql = "SET QUOTED_IDENTIFIER OFF;\nUPDATE users SET ssn = \"123-45-6789\" WHERE email = \"ada@example.com\"";

    $out = redact($sql, DbDriver::Sqlsrv);

    expect($out)->toBe("SET QUOTED_IDENTIFIER OFF;\nUPDATE users SET ssn = ? WHERE email = ?")
        ->and($out)->not->toContain('123-45-6789')->not->toContain('ada@example.com');
});

test('redactor does the same for lower case, other spacing and SET ANSI_DEFAULTS', function (string $sql) {
    $out = redact($sql, DbDriver::Sqlsrv);

    expect($out)->not->toContain('secret')->toContain('?');
})->with([
    'lower case' => ['set quoted_identifier off; select * from t where a = "secret"'],
    'tab and newline' => ["SET\tQUOTED_IDENTIFIER\nOFF; SELECT \"secret\""],
    'ansi defaults' => ['SET ANSI_DEFAULTS OFF; SELECT "secret"'],
    'switch after the value' => ['SELECT "secret"; SET QUOTED_IDENTIFIER OFF'],
    'comma list' => ['SET NOCOUNT ON, QUOTED_IDENTIFIER OFF; SELECT "secret"'],
]);

test('redactor still keeps double-quoted identifiers on SQL Server without that switch', function () {
    expect(redact('SELECT "name" FROM "Users" WHERE id = 7', DbDriver::Sqlsrv))->toBe('SELECT "name" FROM "Users" WHERE id = ?');
});

test('redactor keeps PostgreSQL double-quoted identifiers even if the text mentions QUOTED_IDENTIFIER', function () {
    expect(redact('SELECT "quoted_identifier" FROM t WHERE a = 1', DbDriver::Pgsql))->toBe('SELECT "quoted_identifier" FROM t WHERE a = ?');
});
