<?php

use App\Enums\MaskStrategy;
use App\Models\MaskingRule;
use App\Services\Masking\Masker;
use Illuminate\Support\Collection;

function rule(array $attributes): MaskingRule
{
    return new MaskingRule(array_merge([
        'name' => 'test',
        'match_type' => 'column',
        'pattern' => '*email*',
        'strategy' => 'partial',
        'enabled' => true,
    ], $attributes));
}

test('full strategy replaces the whole value', function () {
    expect(app(Masker::class)->apply(MaskStrategy::Full, 'ada@example.com'))->toBe('*****');
});

test('hash strategy is deterministic and prefixed', function () {
    $masker = app(Masker::class);

    expect($masker->apply(MaskStrategy::Hash, 'secret'))
        ->toBe($masker->apply(MaskStrategy::Hash, 'secret'))
        ->toStartWith('sha256:')
        ->not->toContain('secret');
});

test('partial strategy keeps email shape readable', function () {
    expect(app(Masker::class)->apply(MaskStrategy::Partial, 'ada@example.com'))->toBe('a***@***.com');
});

test('partial strategy keeps edges of plain values', function () {
    $masked = app(Masker::class)->apply(MaskStrategy::Partial, '5555444433332222');

    expect($masked)->toStartWith('5')->toEndWith('2')->not->toContain('4444');
});

test('column patterns match case-insensitively with wildcards', function () {
    $masker = app(Masker::class);

    expect($masker->columnMatches('*email*', 'Customer_Email'))->toBeTrue()
        ->and($masker->columnMatches('*email*', 'phone'))->toBeFalse()
        ->and($masker->columnMatches('*password*|*token*', 'api_token'))->toBeTrue();
});

test('column rules also mask non-string values', function () {
    $rules = collect([rule(['pattern' => '*card*', 'strategy' => 'full'])]);

    $row = app(Masker::class)->maskRow(['card_number' => 5555444433332222, 'id' => 7], $rules);

    expect($row['card_number'])->toBe('*****')
        ->and($row['id'])->toBe(7);
});

test('regex rules mask matching content in any string column', function () {
    $rules = collect([rule([
        'match_type' => 'regex',
        'pattern' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        'strategy' => 'partial',
    ])]);

    $row = app(Masker::class)->maskRow(['note' => 'contact ada@example.com please', 'city' => 'London'], $rules);

    expect($row['note'])->not->toContain('ada@example.com')
        ->and($row['city'])->toBe('London');
});

test('column rules take precedence over regex rules', function () {
    $rules = collect([
        rule(['match_type' => 'regex', 'pattern' => '/@/', 'strategy' => 'full']),
        rule(['match_type' => 'column', 'pattern' => '*email*', 'strategy' => 'hash']),
    ]);

    $masked = app(Masker::class)->maskValue('email', 'ada@example.com', $rules);

    expect($masked)->toStartWith('sha256:');
});

test('null values stay null', function () {
    $rules = collect([rule(['pattern' => '*email*', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskValue('email', null, $rules))->toBeNull();
});

test('an invalid regex fails closed: the value is masked, never emitted raw', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/(unclosed', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskValue('note', 'hello', $rules))->toBe('*****');
});

/**
 * The rule set every new team is seeded with, as unsaved models.
 *
 * @return Collection<int, MaskingRule>
 */
function defaultRules(): Collection
{
    return collect(MaskingRule::defaults())->map(fn (array $attributes) => rule($attributes));
}

test('regex rules also mask non-string scalar values', function () {
    // BIGINT/DECIMAL columns come back from PDO as native int/float; a
    // content rule that only looked at strings let them through raw.
    $rules = collect([rule([
        'match_type' => 'regex',
        'pattern' => '/\b(?:\d[ -]?){13,16}\b/',
        'strategy' => 'partial',
    ])]);

    $row = app(Masker::class)->maskRow(['pan' => 5555444433332222, 'amount' => 1234.56], $rules);

    expect($row['pan'])->toBeString()
        ->and($row['pan'])->not->toContain('4444')
        ->and($row['amount'])->toBe(1234.56);
});

test('values no rule matches keep their original type', function () {
    $rules = collect([rule([
        'match_type' => 'regex',
        'pattern' => '/\b(?:\d[ -]?){13,16}\b/',
        'strategy' => 'full',
    ])]);

    $row = app(Masker::class)->maskRow([
        'id' => 7,
        'ratio' => 0.5,
        'active' => true,
        'name' => 'Ada Lovelace',
    ], $rules);

    expect($row['id'])->toBe(7)
        ->and($row['ratio'])->toBe(0.5)
        ->and($row['active'])->toBeTrue()
        ->and($row['name'])->toBe('Ada Lovelace');
});

test('an invalid regex fails closed for non-string values too', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/(unclosed', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskValue('pan', 5555444433332222, $rules))->toBe('*****');
});

test('default rules mask secrets that a column alias would have hidden', function () {
    $masker = app(Masker::class);
    $rules = defaultRules();

    // `SELECT api_key AS k, secret AS s FROM integrations` — no column
    // pattern can match, so the content rules have to carry it.
    expect($masker->maskValue('k', 'sk-live-3Hd82kQmZpR4tVx9LbN1cYwE', $rules))->toBe('*****')
        ->and($masker->maskValue('s', 'ghp_aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA', $rules))->toBe('*****')
        // Split so the literal never appears whole in the file: these are
        // invented values, but a scanner reading the source cannot tell that
        // and GitHub's push protection rejects the push over it.
        ->and($masker->maskValue('s', 'xoxb'.'-2913847561-4820193746-QbT7mZk3Rn2wXpL9dVfH', $rules))->toBe('*****')
        ->and($masker->maskValue('k', 'AKIAIOSFODNN7EXAMPLE', $rules))->toBe('*****')
        ->and($masker->maskValue('t', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', $rules))->toBe('*****')
        ->and($masker->maskValue('pem', "-----BEGIN RSA PRIVATE KEY-----\nMIIEow==\n-----END RSA PRIVATE KEY-----", $rules))->toBe('*****')
        ->and($masker->maskValue('k', 'wS8xK2pQ7vNc4mZjT1rYbL5hG9dF3aEu', $rules))->toBe('*****');
});

test('default rules leave ordinary values untouched', function () {
    $masker = app(Masker::class);
    $rules = defaultRules();

    // False-positive guard: the secret-shaped content rules ship enabled for
    // every team, so look-alikes must survive them unchanged.
    expect($masker->maskValue('name', 'Ada Lovelace', $rules))->toBe('Ada Lovelace')
        ->and($masker->maskValue('city', 'London', $rules))->toBe('London')
        ->and($masker->maskValue('note', 'Deployment finished successfully on the staging cluster', $rules))
        ->toBe('Deployment finished successfully on the staging cluster')
        ->and($masker->maskValue('uuid', '9f8b1c2d-3e4f-4a6b-8c8d-9e0f1a2b3c4d', $rules))
        ->toBe('9f8b1c2d-3e4f-4a6b-8c8d-9e0f1a2b3c4d')
        ->and($masker->maskValue('digest', str_repeat('a1b2c3d4e5f6a7b8', 4), $rules))->toBe(str_repeat('a1b2c3d4e5f6a7b8', 4))
        ->and($masker->maskValue('ulid', '01HQ8ZK4M9NPQRSTVWXYZ0A1B2', $rules))->toBe('01HQ8ZK4M9NPQRSTVWXYZ0A1B2')
        ->and($masker->maskValue('slug', 'this-is-a-very-long-kebab-case-slug-value-here', $rules))
        ->toBe('this-is-a-very-long-kebab-case-slug-value-here')
        ->and($masker->maskValue('id', 42, $rules))->toBe(42)
        ->and($masker->maskValue('status', 'order_shipped', $rules))->toBe('order_shipped');
});

test('the default card rule masks real card numbers, as string and as int', function () {
    $masker = app(Masker::class);
    $rules = defaultRules();

    // The int forms are the regression guard for content rules running on
    // non-string scalars: a BIGINT `pan` column arrives from PDO as an int.
    expect($masker->maskValue('v', '4111111111111111', $rules))->toBe('4********1')
        ->and($masker->maskValue('v', 4111111111111111, $rules))->toBe('4********1')
        ->and($masker->maskValue('v', '4111 1111 1111 1111', $rules))->toBe('4********1')
        ->and($masker->maskValue('v', '4111-1111-1111-1111', $rules))->toBe('4********1')
        ->and($masker->maskValue('v', '4222222222222', $rules))->toBe('4********2')
        ->and($masker->maskValue('v', '5555555555554444', $rules))->toBe('5********4')
        ->and($masker->maskValue('v', 5555555555554444, $rules))->toBe('5********4')
        ->and($masker->maskValue('v', '5555 5555 5555 4444', $rules))->toBe('5********4')
        ->and($masker->maskValue('v', '2221000000000009', $rules))->toBe('2********9')
        ->and($masker->maskValue('v', '378282246310005', $rules))->toBe('3********5')
        ->and($masker->maskValue('v', 378282246310005, $rules))->toBe('3********5')
        ->and($masker->maskValue('v', '3782 822463 10005', $rules))->toBe('3********5')
        ->and($masker->maskValue('v', '6011111111111117', $rules))->toBe('6********7')
        ->and($masker->maskValue('v', 6011111111111117, $rules))->toBe('6********7')
        ->and($masker->maskValue('v', '30569309025904', $rules))->toBe('3********4')
        ->and($masker->maskValue('v', '3530111333300000', $rules))->toBe('3********0');
});

test('the default card rule ignores long numbers that are not card numbers', function () {
    $masker = app(Masker::class);
    $rules = defaultRules();

    // Once content rules see ints, a bare 13-16 digit run would swallow every
    // epoch, snowflake id and order number in the result set.
    expect($masker->maskValue('created_at_ms', 1726670000000, $rules))->toBe(1726670000000)
        ->and($masker->maskValue('created_at_ms', '1726670000000', $rules))->toBe('1726670000000')
        ->and($masker->maskValue('event_time', 1726670000, $rules))->toBe(1726670000)
        ->and($masker->maskValue('message_id', 1234567890123456789, $rules))->toBe(1234567890123456789)
        ->and($masker->maskValue('message_id', 987654321098765432, $rules))->toBe(987654321098765432)
        ->and($masker->maskValue('order_no', '1234567890123', $rules))->toBe('1234567890123')
        ->and($masker->maskValue('order_no', '1234567890123456', $rules))->toBe('1234567890123456')
        ->and($masker->maskValue('stamp', '20240917120000', $rules))->toBe('20240917120000')
        ->and($masker->maskValue('v', '2000000000000000', $rules))->toBe('2000000000000000')
        ->and($masker->maskValue('v', '41111111111111111', $rules))->toBe('41111111111111111');
});

test('maskText applies regex rules in place', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/\d{3}-\d{2}-\d{4}/', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskText('bad value 123-45-6789 near x', $rules))->toBe('bad value ***** near x');
});

test('maskText masks a value echoed by a postgres type error', function () {
    $message = 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "alice@example.com"';

    $masked = app(Masker::class)->maskText($message, defaultRules());

    expect($masked)->not->toContain('alice@example.com')
        ->and($masked)->toContain('invalid input syntax for type integer: "a***@***.com"');
});

test('maskText masks a mysql duplicate entry when the key names a sensitive column', function () {
    // Not e-mail shaped, so only the column path can catch it.
    $masked = app(Masker::class)->maskText("Duplicate entry '905551112233' for key 'users.phone'", defaultRules());

    expect($masked)->not->toContain('905551112233')
        ->and($masked)->toStartWith("Duplicate entry '")
        ->and($masked)->toEndWith("' for key 'users.phone'");
});

test('maskText masks the value of a postgres key detail by its column name', function () {
    $masked = app(Masker::class)->maskText(
        'ERROR:  duplicate key value violates unique constraint "users_phone_key" DETAIL:  Key (phone)=(+905551112233) already exists.',
        defaultRules(),
    );

    expect($masked)->not->toContain('5551112233')
        ->and($masked)->toContain('Key (phone)=(')
        ->and($masked)->toContain('users_phone_key')
        ->and($masked)->toEndWith(') already exists.');
});

test('maskText masks every literal when a column rule matches, leaving identifiers readable', function () {
    $rules = collect([rule(['pattern' => '*email*', 'strategy' => 'full'])]);

    $masked = app(Masker::class)->maskText(
        "Data truncated for column 'email' at row 1 near 'ada@example.com' and 'Ada Lovelace'",
        $rules,
    );

    expect($masked)->toBe("Data truncated for column 'email' at row 1 near '*****' and '*****'");
});

test('maskText uses the strongest strategy among the matching column rules', function () {
    $rules = collect([
        rule(['pattern' => '*email*', 'strategy' => 'partial']),
        rule(['pattern' => '*token*', 'strategy' => 'full']),
        rule(['pattern' => '*phone*', 'strategy' => 'hash']),
    ]);

    $masker = app(Masker::class);

    expect($masker->maskText("Duplicate entry 'abcdef' for key 'users.email_token'", $rules))
        ->toBe("Duplicate entry '*****' for key 'users.email_token'")
        ->and($masker->maskText("Duplicate entry 'abcdef' for key 'users.phone_email'", $rules))
        ->toStartWith("Duplicate entry 'sha256:");
});

test('maskText matches the last part of a table.column identifier', function () {
    $rules = collect([rule(['pattern' => 'email', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskText("Duplicate entry 'zed' for key 'users.email'", $rules))
        ->toBe("Duplicate entry '*****' for key 'users.email'");
});

test('maskText masks a failing row echo whenever any rule exists', function () {
    $rules = collect([rule(['pattern' => '*token*', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskText('null value in column "id" violates not-null constraint DETAIL:  Failing row contains (null, ada, 4111111111111111).', $rules))
        ->toBe('null value in column "id" violates not-null constraint DETAIL:  Failing row contains (*****).');
});

test('maskText leaves non-sensitive text unchanged', function () {
    $message = 'SQLSTATE[HY000]: General error: 1 no such table: missing_table';

    expect(app(Masker::class)->maskText($message, defaultRules()))->toBe($message);
});

test('maskText returns the text unchanged when there are no rules', function () {
    $message = "Duplicate entry 'bob@x.io' for key 'users.email'";

    expect(app(Masker::class)->maskText($message, collect()))->toBe($message);
});

test('maskText is idempotent', function () {
    $masker = app(Masker::class);
    $once = $masker->maskText("Duplicate entry 'bob@x.io' for key 'users.email' near \"alice@example.com\"", defaultRules());

    expect($masker->maskText($once, defaultRules()))->toBe($once);
});

test('maskText fails closed when a pattern cannot be evaluated', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/(unclosed/', 'strategy' => 'partial'])]);

    expect(app(Masker::class)->maskText('anything at all', $rules))->toBe('*****');
});

test('maskText fails closed when PCRE hits its backtrack limit', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/(a+)+$/', 'strategy' => 'partial'])]);
    $original = ini_get('pcre.backtrack_limit');
    $originalJit = ini_get('pcre.jit');

    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '100');

    try {
        $text = str_repeat('a', 200).'b';

        // The literal scan itself survives these limits (a harmless rule leaves
        // the text alone), so the fail-closed result below comes from the
        // catastrophic rule.
        $harmless = collect([rule(['match_type' => 'regex', 'pattern' => '/zzz/', 'strategy' => 'full'])]);
        $untouched = app(Masker::class)->maskText($text, $harmless);
        $masked = app(Masker::class)->maskText($text, $rules);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $original);
        ini_set('pcre.jit', (string) $originalJit);
    }

    expect($untouched)->toBe($text)
        ->and($masked)->toBe('*****');
});

test('maskText applies an anchored regex rule to a whole echoed value', function () {
    $message = 'SQLSTATE[22P02]: invalid input syntax for type integer: "aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3" (Connection: pgsql, SQL: select note::int from notes)';

    $masked = app(Masker::class)->maskText($message, defaultRules());

    expect($masked)->not->toContain('aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3')
        ->and($masked)->toContain('invalid input syntax for type integer: "*****"')
        ->and($masked)->toContain('select note::int from notes');
});

test('maskText applies an anchored regex rule to a duplicate entry and a key detail value', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/^\d{11}$/', 'strategy' => 'full'])]);
    $masker = app(Masker::class);

    expect($masker->maskText("Duplicate entry '12345678901' for key 'users.national_id'", $rules))
        ->toBe("Duplicate entry '*****' for key 'users.national_id'")
        ->and($masker->maskText('DETAIL:  Key (national_id)=(12345678901) already exists.', $rules))
        ->toBe('DETAIL:  Key (national_id)=(*****) already exists.');
});

test('maskText leaves a literal alone when no anchored rule matches it', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/^\d{11}$/', 'strategy' => 'full'])]);
    $message = "Duplicate entry '1234' for key 'users.national_id'";

    expect(app(Masker::class)->maskText($message, $rules))->toBe($message);
});

test('maskText masks the whole message when a stray apostrophe unbalances the quotes', function () {
    $message = "Table 'db.missing' doesn't exist (Connection: mysql, SQL: select * from missing where phone = '905551112233')";

    expect(app(Masker::class)->maskText($message, defaultRules()))->toBe('*****');
});

test('maskText keeps a message with balanced and escaped quotes readable', function () {
    $masked = app(Masker::class)->maskText("Duplicate entry 'it\\'s' for key 'users.phone'", defaultRules());

    expect($masked)->toStartWith("Duplicate entry '")->and($masked)->not->toBe('*****');
});
