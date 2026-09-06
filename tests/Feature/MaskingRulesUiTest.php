<?php

use App\Livewire\Masking\Index;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

function maskingSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    return [$team, $dba];
}

test('masking page requires the dba role', function () {
    [$team] = maskingSetup();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);

    $this->actingAs($developer)->get(route('masking.index'))->assertForbidden();
});

test('a dba can create a column rule', function () {
    [$team, $dba] = maskingSetup();

    Livewire::actingAs($dba)
        ->test(Index::class)
        ->call('openCreate')
        ->set('name', 'IBANs')
        ->set('matchType', 'column')
        ->set('pattern', '*iban*')
        ->set('strategy', 'hash')
        ->call('save')
        ->assertHasNoErrors();

    expect(MaskingRule::where('team_id', $team->id)->where('name', 'IBANs')->exists())->toBeTrue();
});

test('invalid regex patterns are rejected', function () {
    [, $dba] = maskingSetup();

    Livewire::actingAs($dba)
        ->test(Index::class)
        ->call('openCreate')
        ->set('name', 'Broken')
        ->set('matchType', 'regex')
        ->set('pattern', '/(unclosed')
        ->set('strategy', 'full')
        ->call('save')
        ->assertHasErrors('pattern');

    expect(MaskingRule::count())->toBe(0);
});

test('defaults can be added idempotently', function () {
    [$team, $dba] = maskingSetup();

    $component = Livewire::actingAs($dba)->test(Index::class);
    $component->call('addDefaults');
    $component->call('addDefaults');

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('the playground previews masking with enabled rules', function () {
    [$team, $dba] = maskingSetup();
    MaskingRule::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($dba)
        ->test(Index::class)
        ->set('sampleColumn', 'email')
        ->set('sampleValue', 'ada@example.com')
        ->call('preview')
        ->assertSet('sampleResult', 'a***@***.com');
});

test('rules can be toggled and deleted', function () {
    [$team, $dba] = maskingSetup();
    $rule = MaskingRule::factory()->create(['team_id' => $team->id]);

    $component = Livewire::actingAs($dba)->test(Index::class);

    $component->call('toggle', $rule->id);
    expect($rule->fresh()->enabled)->toBeFalse();

    $component->call('deleteRule', $rule->id);
    expect(MaskingRule::find($rule->id))->toBeNull();
});
