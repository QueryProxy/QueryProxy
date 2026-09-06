<?php

use App\Enums\TeamRole;
use App\Livewire\Admin\TeamMembers;
use App\Livewire\Admin\Teams;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('role helpers reflect pivot roles', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'dba']);

    expect($user->roleIn($team))->toBe(TeamRole::Dba)
        ->and($user->isDbaIn($team))->toBeTrue()
        ->and($user->isDeveloperIn($team))->toBeFalse()
        ->and($user->belongsToTeam($team))->toBeTrue();
});

test('non-members have no role and no membership', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    expect($user->roleIn($team))->toBeNull()
        ->and($user->belongsToTeam($team))->toBeFalse();
});

test('admins implicitly belong to every team', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    expect($admin->belongsToTeam($team))->toBeTrue()
        ->and($admin->accessibleTeams()->pluck('id'))->toContain($team->id);
});

test('users cannot switch to a team they do not belong to', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('teams.switch', $team))->assertForbidden();
});

test('members can switch to their team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);

    $this->actingAs($user)->post(route('teams.switch', $team))->assertRedirect(route('dashboard'));

    expect(session('current_team_id'))->toBe($team->id);
});

test('admin routes are forbidden for non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.teams'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users'))->assertForbidden();
});

test('admins can create teams', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Teams::class)
        ->set('name', 'Platform Squad')
        ->call('createTeam');

    expect(Team::where('name', 'Platform Squad')->exists())->toBeTrue();
});

test('admins can add members with a role', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(TeamMembers::class, ['team' => $team])
        ->set('email', $user->email)
        ->set('role', 'auditor')
        ->call('addMember')
        ->assertHasNoErrors();

    expect($user->fresh()->roleIn($team))->toBe(TeamRole::Auditor);
});

test('adding an unknown email surfaces an error', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();

    Livewire::actingAs($admin)
        ->test(TeamMembers::class, ['team' => $team])
        ->set('email', 'ghost@example.com')
        ->set('role', 'developer')
        ->call('addMember')
        ->assertHasErrors('email');
});
