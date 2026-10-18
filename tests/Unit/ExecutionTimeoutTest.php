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
