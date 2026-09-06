<?php

use App\Enums\MaskStrategy;
use App\Models\MaskingRule;
use App\Services\Masking\Masker;

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

test('an invalid regex never matches and never crashes', function () {
    $rules = collect([rule(['match_type' => 'regex', 'pattern' => '/(unclosed', 'strategy' => 'full'])]);

    expect(app(Masker::class)->maskValue('note', 'hello', $rules))->toBe('hello');
});
