<?php

use App\Support\ExecutionTimeout;

test('the maximum is 24 hours', function () {
    expect(ExecutionTimeout::MAX)->toBe(86400);
});

test('a valid value up to the maximum is used as written', function (string $raw, int $expected) {
    expect(ExecutionTimeout::resolve($raw))->toBe($expected);
})->with([
    'one' => ['1', 1],
    'default' => ['300', 300],
    'just below the maximum' => ['86399', 86399],
    'the maximum' => ['86400', 86400],
    'the maximum with leading zeros' => ['000086400', 86400],
    'leading zeros' => ['0090', 90],
]);

test('a value above the maximum is clamped to it', function (string $raw) {
    expect(ExecutionTimeout::resolve($raw))->toBe(86400);
})->with([
    'one over' => ['86401'],
    'six digits' => ['100000'],
    'leading zeros, one over' => ['0086401'],
    '30 digits' => [str_repeat('9', 30)],
    '30 digits with leading zeros' => ['000'.str_repeat('9', 30)],
    'PHP_INT_MAX' => [(string) PHP_INT_MAX],
    'one past PHP_INT_MAX' => ['9223372036854775808'],
]);

test('an invalid value falls back to the default', function (mixed $raw) {
    expect(ExecutionTimeout::resolve($raw))->toBe(ExecutionTimeout::DEFAULT);
})->with([
    'text' => ['abc'],
    'empty' => [''],
    'zero' => ['0'],
    'zeros' => ['0000'],
    'negative' => ['-5'],
    'decimal' => ['12.5'],
    'padded' => [' 60'],
    'null' => [null],
    'bool' => [true],
    'int' => [60],
]);

test('the lock timeout follows the same rule with its own default and a one hour maximum', function (mixed $raw, int $expected) {
    expect(ExecutionTimeout::resolveLock($raw))->toBe($expected);
})->with([
    'valid' => ['7', 7],
    'leading zeros' => ['0030', 30],
    'the maximum' => ['3600', 3600],
    'above the maximum' => ['3601', 3600],
    'a value that would overflow a 32-bit millisecond setting' => ['3000000', 3600],
    'huge' => [str_repeat('9', 30), 3600],
    'text' => ['abc', 5],
    'empty' => ['', 5],
    'zero' => ['0', 5],
    'negative' => ['-1', 5],
    'decimal' => ['2.5', 5],
    'not a string' => [null, 5],
]);
