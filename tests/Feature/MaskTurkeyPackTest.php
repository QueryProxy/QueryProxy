<?php

use App\Enums\MaskStrategy;
use App\Livewire\Masking\Index;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Masking\Masker;
use Livewire\Livewire;

// Synthetic data only: checksum-valid numbers that belong to nobody.

function trPackTeam(): array
{
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    return [$team, app(Masker::class)->rulesFor($connection), app(Masker::class)];
}

test('the default set includes the Turkey pack', function () {
    $names = collect(MaskingRule::defaults())->pluck('name');

    foreach (MaskingRule::turkeyDefaults() as $rule) {
        expect($names)->toContain($rule['name']);
    }
});

test('a phone number under an alias is masked by the content rule (M2)', function () {
    [, $rules, $masker] = trPackTeam();

    foreach (['0532 123 45 67', '+90 532 123 45 67', '05321234567', '532-123-45-67'] as $phone) {
        expect($masker->maskValue('p', $phone, $rules))->not->toBe($phone);
    }
});

test('a BIGINT national id under an alias is masked (M6)', function () {
    [$team, $rules, $masker] = trPackTeam();

    $masked = $masker->maskValue('x', 10000000146, $rules);

    expect($masked)->toBe('hmac:'.substr(hash_hmac('sha256', '10000000146', $team->masking_key), 0, 16));
});

test('an id that fails the TCKN checksum is left alone', function () {
    [, $rules, $masker] = trPackTeam();

    expect($masker->maskValue('x', 10000000147, $rules))->toBe(10000000147);
});

test('national id, tax id, iban and plate columns are masked', function () {
    [, $rules, $masker] = trPackTeam();

    $row = $masker->maskRow([
        'tc_kimlik' => 10000000146,
        'national_id' => '10000000146',
        'vkn' => '1234567890',
        'tax_no' => '1234567890',
        'iban' => 'TR33 0006 1005 1978 6457 8413 26',
        'plate' => '34 ABC 123',
        'city' => 'Ankara',
    ], $rules);

    expect($row['tc_kimlik'])->toStartWith('hmac:')
        ->and($row['national_id'])->toBe($row['tc_kimlik'])
        ->and($row['vkn'])->toStartWith('hmac:')
        ->and($row['tax_no'])->toBe($row['vkn'])
        ->and($row['iban'])->toStartWith('hmac:')
        ->and($row['plate'])->not->toBe('34 ABC 123')
        ->and($row['city'])->toBe('Ankara');
});

test('a valid iban in a free column is masked, an altered one is not', function () {
    [, $rules, $masker] = trPackTeam();

    expect($masker->maskValue('note', 'TR33 0006 1005 1978 6457 8413 26', $rules))->toStartWith('hmac:')
        ->and($masker->maskValue('note', 'TR33 0006 1005 1978 6457 8413 27', $rules))->toBe('TR33 0006 1005 1978 6457 8413 27');
});

test('e164 numbers and vehicle plates are masked by content rules', function () {
    [, $rules, $masker] = trPackTeam();

    expect($masker->maskValue('c', '+4915112345678', $rules))->not->toBe('+4915112345678')
        ->and($masker->maskValue('c', '06 AB 1234', $rules))->not->toBe('06 AB 1234')
        ->and($masker->maskValue('c', 'ordinary text', $rules))->toBe('ordinary text');
});

test('a valid card number is masked and a lookalike order number is not', function () {
    [, $rules, $masker] = trPackTeam();

    expect($masker->maskValue('c', '4111 1111 1111 1111', $rules))->not->toBe('4111 1111 1111 1111')
        ->and($masker->maskValue('c', '4111 1111 1111 1112', $rules))->toBe('4111 1111 1111 1112');
});

test('the VKN content validator ships disabled so it cannot mask arbitrary 10-digit ids', function () {
    [$team, $rules, $masker] = trPackTeam();

    $rule = MaskingRule::where('team_id', $team->id)->where('match_type', 'validator')->where('pattern', 'vkn')->firstOrFail();

    expect($rule->enabled)->toBeFalse()
        ->and($masker->maskValue('ts', 1234567890, $rules))->toBe(1234567890);
});

test('error text is masked with validator rules too', function () {
    [, $rules, $masker] = trPackTeam();

    $text = $masker->maskText('Duplicate entry for 10000000146 and 10000000147', $rules);

    expect($text)->not->toContain('10000000146')->toContain('10000000147');
});

test('a validator rule naming an unknown validator fails closed', function () {
    $team = Team::factory()->create();
    $rule = MaskingRule::create([
        'team_id' => $team->id, 'name' => 'broken', 'match_type' => 'validator', 'pattern' => 'nope', 'strategy' => 'partial',
    ]);

    expect(app(Masker::class)->maskValue('x', 'anything', collect([$rule])))->not->toBe('anything');
});

function dbaForTrPack(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    return [$team, $dba];
}

test('Add TR rules installs only the pack, once', function () {
    [$team, $dba] = dbaForTrPack();
    MaskingRule::where('team_id', $team->id)->delete();

    Livewire::actingAs($dba)->test(Index::class)->call('addTurkeyRules');
    Livewire::actingAs($dba)->test(Index::class)->call('addTurkeyRules');

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::turkeyDefaults()))
        ->and(AuditLog::where('action', 'masking_rule.tr_rules_added')->where('team_id', $team->id)->count())->toBe(2);
});

test('Add TR rules is forbidden for a developer', function () {
    [$team] = dbaForTrPack();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);

    Livewire::actingAs($developer)->test(Index::class)->call('addTurkeyRules')->assertForbidden();
});

test('a validator rule can be created from the UI and unknown validators are rejected', function () {
    [$team, $dba] = dbaForTrPack();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('name', 'IBAN check')->set('matchType', 'validator')->set('strategy', 'hmac')
        ->assertSet('pattern', 'tckn')
        ->set('pattern', 'iban_tr')
        ->call('save')->assertHasNoErrors();

    expect(MaskingRule::where('team_id', $team->id)->where('name', 'IBAN check')->where('pattern', 'iban_tr')->exists())->toBeTrue();

    Livewire::actingAs($dba)->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Bad')->set('matchType', 'validator')->set('strategy', 'full')
        ->set('pattern', '/\d+/')
        ->call('save')->assertHasErrors(['pattern']);
});

test('the hash strategy is flagged as deprecated and hmac is not', function () {
    expect(MaskStrategy::Hash->isDeprecated())->toBeTrue()
        ->and(MaskStrategy::Hmac->isDeprecated())->toBeFalse()
        ->and(MaskStrategy::Hash->label())->toContain('deprecated');
});

test('upper-case GUIDs are never mistaken for plates or phone numbers', function () {
    [, $rules, $masker] = trPackTeam();

    // The reviewer's counter-example, then a seeded sweep: before the token
    // boundary fix about 1% of random upper-case GUIDs were masked by the
    // plate rule and a further ~0.2% by the phone rule.
    $guids = ['44FA2749-73BE-1BFE-0B65-EADDAF37E9E5'];
    mt_srand(20261020);

    for ($i = 0; $i < 20000; $i++) {
        $guids[] = strtoupper(sprintf('%08x-%04x-%04x-%04x-%04x%08x', mt_rand(0, 0xFFFFFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFFFFFF)));
    }

    $guids = [...$guids, ...array_map('strtolower', $guids)];

    $masked = array_filter($guids, fn (string $guid): bool => $masker->maskValue('id', $guid, $rules) !== $guid);

    expect($masked)->toBe([]);
});

test('plates are still found when surrounded by spaces or punctuation', function () {
    [, $rules, $masker] = trPackTeam();

    expect($masker->maskValue('note', 'vehicle 34 ABC 123, parked', $rules))->not->toBe('vehicle 34 ABC 123, parked')
        ->and($masker->maskValue('note', 'plate (06AB1234)', $rules))->not->toBe('plate (06AB1234)');
});

test('the fractional part of a decimal number is not mistaken for a mobile number', function () {
    [, $rules, $masker] = trPackTeam();

    foreach (['0.5000000000', '3.5123456789', '28.5012345678'] as $decimal) {
        expect($masker->maskValue('rate', $decimal, $rules))->toBe($decimal);
    }

    expect($masker->maskValue('p', '05321234567', $rules))->not->toBe('05321234567')
        ->and($masker->maskValue('p', 'call 0532 123 45 67.', $rules))->not->toBe('call 0532 123 45 67.');
});

test('comma-separated phone lists (GROUP_CONCAT) are masked, both numbers', function () {
    [, $rules, $masker] = trPackTeam();

    foreach (['05321234567,05339876543', '5321234567,5339876543', '0532 123 45 67,0533 987 65 43'] as $list) {
        $masked = $masker->maskValue('p', $list, $rules);

        expect($masked)->not->toContain('1234567')
            ->and($masked)->not->toContain('9876543');
    }

    // A comma-decimal (Turkish locale) that happens to look like a phone is
    // masked: the cost of treating `,` as a list separator, accepted as fail-closed.
    expect($masker->maskValue('rate', '1,5321234567', $rules))->not->toBe('1,5321234567');
});
