<?php

use App\Livewire\Admin\Teams;
use App\Livewire\Admin\Users;
use App\Livewire\Masking\Index as MaskingIndex;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

// Route middleware only guards the initial page load; Livewire action calls
// arrive on a separate endpoint. These assert the in-component checks that
// stop a non-admin / demoted user from driving privileged actions there.

test('a non-admin cannot toggle admin through the users component', function () {
    $attacker = User::factory()->create(['is_admin' => false]);
    $victim = User::factory()->create(['is_admin' => false]);

    Livewire::actingAs($attacker)
        ->test(Users::class)
        ->call('toggleAdmin', $victim->id)
        ->assertForbidden();

    expect($victim->fresh()->is_admin)->toBeFalse();
});

test('a non-admin cannot create a team through the teams component', function () {
    $attacker = User::factory()->create(['is_admin' => false]);

    Livewire::actingAs($attacker)
        ->test(Teams::class)
        ->set('name', 'Sneaky')
        ->call('createTeam')
        ->assertForbidden();

    expect(Team::where('name', 'Sneaky')->exists())->toBeFalse();
});

test('a developer cannot save a masking rule through the masking component', function () {
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)
        ->test(MaskingIndex::class)
        ->set('name', 'x')
        ->set('matchType', 'column')
        ->set('pattern', 'email')
        ->set('strategy', 'full')
        ->call('save')
        ->assertForbidden();
});

test('an admin can still toggle admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $victim = User::factory()->create(['is_admin' => false]);

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('toggleAdmin', $victim->id)
        ->assertHasNoErrors();

    expect($victim->fresh()->is_admin)->toBeTrue();
});
