<?php

use App\Enums\DbDriver;
use App\Services\Sql\FilterColumnExtractor;

function filterColumns(string $sql, ?DbDriver $driver = null): array
{
    return (new FilterColumnExtractor)->extract($sql, $driver);
}

test('SensitiveFilter extractor finds columns in WHERE', function () {
    expect(filterColumns("SELECT id FROM customers WHERE email = 'a@b.c' AND age > 30", DbDriver::Mysql))
        ->toBe(['email', 'age']);
});

test('SensitiveFilter extractor ignores the select list', function () {
    expect(filterColumns('SELECT email, phone, c.card_no AS card FROM customers c', DbDriver::Pgsql))->toBe([]);
    expect(filterColumns('SELECT email FROM customers WHERE id = 1', DbDriver::Pgsql))->toBe(['id']);
});

test('SensitiveFilter extractor finds JOIN ON and USING columns', function () {
    expect(filterColumns('SELECT 1 FROM a JOIN b ON a.email = b.contact_email LEFT JOIN c USING (phone)', DbDriver::Mysql))
        ->toBe(['email', 'contact_email', 'phone']);
});

test('SensitiveFilter extractor finds HAVING, GROUP BY and ORDER BY columns', function () {
    expect(filterColumns('SELECT count(*) FROM t GROUP BY email HAVING max(token) > 1 ORDER BY phone DESC', DbDriver::Mysql))
        ->toBe(['email', 'token', 'phone']);
});

test('SensitiveFilter extractor ends a filter at the next clause', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE a = 1 LIMIT 5 OFFSET 2', DbDriver::Pgsql))->toBe(['a']);
    expect(filterColumns('SELECT 1 FROM t WHERE a = 1 UNION SELECT email FROM u', DbDriver::Pgsql))->toBe(['a']);
});

test('SensitiveFilter extractor looks into subqueries and function arguments', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE id IN (SELECT user_id FROM u WHERE lower(email) = 1)', DbDriver::Pgsql))
        ->toBe(['id', 'email']);
});

test('SensitiveFilter extractor reports the last part of a qualified name once, lower-cased', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE public.t.Email = 1 OR T . EMAIL = 2', DbDriver::Pgsql))->toBe(['email']);
});

test('SensitiveFilter extractor reads quoted identifiers', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE "Email" = 1 AND "a b" = 2', DbDriver::Pgsql))->toBe(['email', 'a b']);
    expect(filterColumns('SELECT 1 FROM t WHERE `Email` = 1 AND `a b` = 2', DbDriver::Mysql))->toBe(['email', 'a b']);
    expect(filterColumns('SELECT 1 FROM t WHERE [Email] = 1', DbDriver::Sqlsrv))->toBe(['email']);
});

test('SensitiveFilter extractor does not read literals as columns', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE note = 'email = x' AND n = E'it\\'s email' AND x = \$q\$ email \$q\$", DbDriver::Pgsql))
        ->toBe(['note', 'n', 'x']);
    expect(filterColumns('SELECT 1 FROM t WHERE note = "email" AND n = \'a\\\' email\'', DbDriver::Mysql))
        ->toBe(['note', 'n']);
});

test('SensitiveFilter extractor treats double quotes as identifiers when ANSI_QUOTES is mentioned', function () {
    expect(filterColumns('SET sql_mode = \'ANSI_QUOTES\'; SELECT 1 FROM t WHERE "email" = 1', DbDriver::Mysql))->toBe(['email']);
});

test('SensitiveFilter extractor ignores comments', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE a = 1 -- email\n AND /* phone */ b = 2 # card\n", DbDriver::Mysql))
        ->toBe(['a', 'b']);
});

test('SensitiveFilter extractor ignores function names, keywords, casts and parameters', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE created_at > now() AND id::int = \$1 AND k = :token AND s IS NOT NULL AND p LIKE 'x' AND u = @email", DbDriver::Pgsql))
        ->toBe(['created_at', 'id', 'k', 's', 'p', 'u']);
});

test('SensitiveFilter extractor does not read ON CONFLICT or ON DUPLICATE KEY as a join', function () {
    expect(filterColumns('INSERT INTO t (email) VALUES (1) ON DUPLICATE KEY UPDATE email = 2', DbDriver::Mysql))->toBe([]);
    expect(filterColumns('INSERT INTO t (email) VALUES (1) ON CONFLICT (email) DO NOTHING', DbDriver::Pgsql))->toBe([]);
});

test('SensitiveFilter extractor ignores window and aggregate ORDER BY', function () {
    expect(filterColumns('SELECT row_number() OVER (PARTITION BY email ORDER BY phone), string_agg(token, \',\' ORDER BY card) FROM t', DbDriver::Pgsql))
        ->toBe([]);
});

test('SensitiveFilter extractor covers UPDATE and DELETE filters but not SET targets', function () {
    expect(filterColumns("UPDATE t SET email = 'x' WHERE id = 1", DbDriver::Mysql))->toBe(['id']);
    expect(filterColumns('DELETE FROM t WHERE phone = 1', DbDriver::Mysql))->toBe(['phone']);
});

test('SensitiveFilter extractor resets at a statement boundary', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE a = 1; SELECT email FROM u', DbDriver::Pgsql))->toBe(['a']);
});

test('SensitiveFilter extractor treats LEFT and RIGHT calls as functions', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE left(email, 3) = 1', DbDriver::Pgsql))->toBe(['email']);
});

test('SensitiveFilter extractor keeps findings when the text is cut off', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE a = 1 AND b = 'open", DbDriver::Pgsql))->toBe(['a', 'b']);
    expect(filterColumns('SELECT 1 FROM t WHERE a = 1 /* open', DbDriver::Pgsql))->toBe(['a']);
});

test('SensitiveFilter extractor merges both quote readings for an unknown driver', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE a = '\\' AND email = 1", null))->toContain('email');
});

test('SensitiveFilter extractor returns nothing for empty text or a plain select', function () {
    expect(filterColumns(''))->toBe([]);
    expect(filterColumns('SELECT 1'))->toBe([]);
});

test('SensitiveFilter extractor keeps a column after a JSONB or other @ operator', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE data @> \'{"vip":true}\' AND email = \'a@b.c\'', DbDriver::Pgsql))
        ->toBe(['data', 'email']);
    expect(filterColumns('SELECT 1 FROM t WHERE tags <@ \'{a}\' AND email = 1 AND j @? \'$.a\' AND phone = 2', DbDriver::Pgsql))
        ->toBe(['tags', 'email', 'j', 'phone']);
    expect(filterColumns('SELECT 1 FROM t WHERE email = 1 AND data @> \'{}\'', DbDriver::Pgsql))
        ->toBe(['email', 'data']);
});

test('SensitiveFilter extractor keeps a column after an array slice colon', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE tags[1:2] = '{a}' AND email = 'a@b.c'", DbDriver::Pgsql))
        ->toBe(['tags', 'email']);
});

test('SensitiveFilter extractor keeps a column after a MySQL variable assignment', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE (@a := 1) AND email = 'a@b.c'", DbDriver::Mysql))->toBe(['email']);
    expect(filterColumns('SELECT 1 FROM t WHERE x = @a AND email = 1 AND y = @@version AND phone = 2', DbDriver::Mysql))
        ->toBe(['x', 'email', 'y', 'phone']);
});

test('SensitiveFilter extractor keeps a column after a cast to a noise-word type', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE d > now() - '1 day'::interval AND email = 1", DbDriver::Pgsql))
        ->toBe(['d', 'email']);
});

test('SensitiveFilter extractor reads the argument of FROM/FOR function syntax', function () {
    expect(filterColumns("SELECT 1 FROM t WHERE trim(both ' ' from email) = 'a@b.c'", DbDriver::Pgsql))->toBe(['email']);
    expect(filterColumns('SELECT 1 FROM t WHERE extract(year from birth_date) = 1990 AND substring(phone from 1 for 3) = \'555\'', DbDriver::Pgsql))
        ->toBe(['year', 'birth_date', 'phone']);
});

test('SensitiveFilter extractor still ends the filter at a FROM inside a subquery', function () {
    expect(filterColumns('SELECT 1 FROM t WHERE id IN (SELECT user_id FROM u WHERE x = 1) AND email = 1', DbDriver::Pgsql))
        ->toBe(['id', 'x', 'email']);
    expect(filterColumns('SELECT 1 FROM t WHERE id = ANY(ARRAY(SELECT a FROM u)) AND email = 1', DbDriver::Pgsql))
        ->toBe(['id', 'email']);
});
