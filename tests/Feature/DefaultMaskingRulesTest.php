<?php

use App\Livewire\Admin\Teams;
use App\Livewire\Masking\Index as MaskingIndex;
use App\Livewire\Studio\QueryStudio;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

const NO_MASKING_RULES_BANNER = 'This team has no masking rules — query results are returned unmasked.';
const NO_ENABLED_MASKING_RULES_BANNER = 'This team has no enabled masking rules — query results are returned unmasked.';

function teamWithMember(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => $role]);
    session(['current_team_id' => $team->id]);

    return [$team, $user];
}

test('a team created from the admin ui starts with the default masking rules', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Teams::class)
        ->set('name', 'Payments')
        ->call('createTeam')
        ->assertHasNoErrors();

    $team = Team::where('name', 'Payments')->sole();

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('a factory-created team starts with the default masking rules', function () {
    $team = Team::factory()->create();

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('installing the defaults again creates no duplicates', function () {
    $team = Team::factory()->create();

    expect($team->installDefaultMaskingRules())->toBe(0)
        ->and($team->installDefaultMaskingRules())->toBe(0)
        ->and(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('installing the defaults only restores the missing ones', function () {
    $team = Team::factory()->create();
    $team->maskingRules()->limit(2)->get()->each->delete();

    expect($team->installDefaultMaskingRules())->toBe(2)
        ->and(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('the defaults are scoped to the team that was created', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();

    expect(MaskingRule::where('team_id', $first->id)->count())->toBe(count(MaskingRule::defaults()))
        ->and(MaskingRule::where('team_id', $second->id)->count())->toBe(count(MaskingRule::defaults()))
        ->and(MaskingRule::count())->toBe(2 * count(MaskingRule::defaults()));
});

test('the demo seeder does not duplicate the default rules', function () {
    $this->seed(DatabaseSeeder::class);

    $team = Team::where('slug', 'demo-team')->sole();

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('masking and studio pages warn when the team has no masking rules', function () {
    [$team, $dba] = teamWithMember('dba');
    $team->maskingRules()->delete();

    Livewire::actingAs($dba)
        ->test(MaskingIndex::class)
        ->assertSee(NO_MASKING_RULES_BANNER);

    Livewire::actingAs($dba)
        ->test(QueryStudio::class)
        ->assertSee(NO_MASKING_RULES_BANNER);
});

test('the masking banner disappears once the defaults are added', function () {
    [$team, $dba] = teamWithMember('dba');
    $team->maskingRules()->delete();

    Livewire::actingAs($dba)
        ->test(MaskingIndex::class)
        ->assertSee(NO_MASKING_RULES_BANNER)
        ->call('addDefaults')
        ->assertDontSee(NO_MASKING_RULES_BANNER);

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(count(MaskingRule::defaults()));
});

test('masking and studio pages show no warning when the team has rules', function () {
    [, $dba] = teamWithMember('dba');

    Livewire::actingAs($dba)
        ->test(MaskingIndex::class)
        ->assertDontSee(NO_MASKING_RULES_BANNER)
        ->assertDontSee(NO_ENABLED_MASKING_RULES_BANNER);

    Livewire::actingAs($dba)
        ->test(QueryStudio::class)
        ->assertDontSee(NO_MASKING_RULES_BANNER)
        ->assertDontSee(NO_ENABLED_MASKING_RULES_BANNER);
});

test('a team created without events has no rules and shows the warning to developers', function () {
    $team = Team::withoutEvents(fn () => Team::factory()->create());
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    session(['current_team_id' => $team->id]);

    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(0);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->assertSee(NO_MASKING_RULES_BANNER);
});

test('a team whose rules are all disabled still shows the warning', function () {
    [$team, $dba] = teamWithMember('dba');
    $team->maskingRules()->update(['enabled' => false]);

    Livewire::actingAs($dba)
        ->test(MaskingIndex::class)
        ->assertSee(NO_ENABLED_MASKING_RULES_BANNER)
        ->assertSee('Every rule below is disabled; enable the ones you need.')
        ->assertDontSee(NO_MASKING_RULES_BANNER);

    Livewire::actingAs($dba)
        ->test(QueryStudio::class)
        ->assertSee(NO_ENABLED_MASKING_RULES_BANNER.' Ask a DBA to enable masking rules.')
        ->assertDontSee(NO_MASKING_RULES_BANNER);
});

test('the studio warning tells a developer whom to ask', function () {
    [$team, $developer] = teamWithMember('developer');
    $team->maskingRules()->delete();

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->assertSee(NO_MASKING_RULES_BANNER.' Ask a DBA to add masking rules.');
});

/** Make the n-th masking rule insert from now on blow up. */
function failMaskingRuleInsertAt(int $n): void
{
    $count = 0;

    MaskingRule::creating(function () use (&$count, $n) {
        if (++$count === $n) {
            throw new RuntimeException('simulated insert failure');
        }
    });
}

test('a failed default install leaves no partial rule set behind', function () {
    $team = Team::withoutEvents(fn () => Team::factory()->create());
    failMaskingRuleInsertAt(4);

    expect(fn () => $team->installDefaultMaskingRules())->toThrow(RuntimeException::class);
    expect(MaskingRule::where('team_id', $team->id)->count())->toBe(0);
});

test('creating a team from the admin ui is all-or-nothing', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    failMaskingRuleInsertAt(4);

    expect(fn () => Livewire::actingAs($admin)
        ->test(Teams::class)
        ->set('name', 'Payments')
        ->call('createTeam'))->toThrow(RuntimeException::class);

    expect(Team::where('name', 'Payments')->exists())->toBeFalse()
        ->and(MaskingRule::count())->toBe(0);
});
