<?php

use App\Enums\MaskValidator;

// All numbers below are synthetic: they satisfy (or deliberately break) the
// checksum but belong to nobody.

// One test per validator, looping over cases: Pest keeps every dataset case as
// a separate TestCase in memory and the whole suite runs close to PHP's default
// 128M limit.

test('tckn accepts valid check digits and rejects everything else', function () {
    foreach (['10000000146', '12345678950'] as $value) {
        expect(MaskValidator::Tckn->isValid($value))->toBeTrue($value);
    }

    foreach ([
        'wrong tenth digit' => '10000000246',
        'wrong eleventh digit' => '10000000147',
        'leading zero' => '01234567890',
        'ten digits' => '1000000014',
        'twelve digits' => '100000001460',
        'letters' => '1000000014a',
        'all same digits' => '11111111111',
    ] as $case => $value) {
        expect(MaskValidator::Tckn->isValid($value))->toBeFalse($case);
    }
});

test('vkn accepts a valid check digit and rejects everything else', function () {
    foreach (['1234567890', '9876543217'] as $value) {
        expect(MaskValidator::Vkn->isValid($value))->toBeTrue($value);
    }

    foreach ([
        'wrong check digit' => '1234567891',
        'wrong check digit two' => '9876543210',
        'nine digits' => '123456789',
        'eleven digits' => '12345678901',
        'letters' => '12345678ab',
    ] as $case => $value) {
        expect(MaskValidator::Vkn->isValid($value))->toBeFalse($case);
    }
});

test('iban_tr accepts valid TR IBANs and rejects everything else', function () {
    foreach ([
        'spaced' => 'TR33 0006 1005 1978 6457 8413 26',
        'compact' => 'TR330006100519786457841326',
        'lower case' => 'tr330006100519786457841326',
    ] as $case => $value) {
        expect(MaskValidator::IbanTr->isValid($value))->toBeTrue($case);
    }

    foreach ([
        'wrong check digits' => 'TR34 0006 1005 1978 6457 8413 26',
        'altered account digit' => 'TR33 0006 1005 1978 6457 8413 27',
        'too short' => 'TR33 0006 1005 1978 6457 8413',
        'other country' => 'DE89 3704 0044 0532 0130 00',
    ] as $case => $value) {
        expect(MaskValidator::IbanTr->isValid($value))->toBeFalse($case);
    }
});

test('luhn accepts valid card numbers and rejects failed checksums', function () {
    foreach ([
        'visa' => '4111111111111111',
        'visa grouped' => '4111 1111 1111 1111',
        'mastercard' => '5555 5555 5555 4444',
        'amex' => '3782 822463 10005',
        'hyphenated' => '4111-1111-1111-1111',
    ] as $case => $value) {
        expect(MaskValidator::Luhn->isValid($value))->toBeTrue($case);
    }

    foreach ([
        'last digit off' => '4111111111111112',
        'grouped, last digit off' => '4111 1111 1111 1112',
        'too short' => '411111111111',
        'too long' => '41111111111111111111',
    ] as $case => $value) {
        expect(MaskValidator::Luhn->isValid($value))->toBeFalse($case);
    }
});

test('matches finds a valid candidate inside longer text', function () {
    expect(MaskValidator::Tckn->matches('kimlik: 10000000146 kayıtlı'))->toBeTrue()
        ->and(MaskValidator::Tckn->matches('kimlik: 10000000147 kayıtlı'))->toBeFalse()
        ->and(MaskValidator::Tckn->matches('serial 910000000146'))->toBeFalse()
        ->and(MaskValidator::IbanTr->matches('hesap TR33 0006 1005 1978 6457 8413 26.'))->toBeTrue()
        ->and(MaskValidator::Luhn->matches('order 4111 1111 1111 1112'))->toBeFalse()
        ->and(MaskValidator::Luhn->matches('card 4111 1111 1111 1111'))->toBeTrue();
});

test('replaceIn masks only candidates with a valid checksum', function () {
    $masked = MaskValidator::Tckn->replaceIn(
        'valid 10000000146 invalid 10000000147',
        fn (string $match): string => '['.strlen($match).']',
    );

    expect($masked)->toBe('valid [11] invalid 10000000147');
});

test('every validator has a label and a candidate pattern', function () {
    foreach (MaskValidator::cases() as $validator) {
        expect($validator->label())->not->toBe('')
            ->and(@preg_match($validator->candidatePattern(), ''))->not->toBeFalse();
    }
});
