<?php

use App\Models\Team;
use App\Models\User;

test('dashboard renders for a team member', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee($team->name);
});

test('dashboard renders for a user without a team', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('not a member of any team');
});

test('admin pages render for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $team = Team::factory()->create();

    // Assert on copy unique to each page: the sidebar now renders "Teams & Users"
    // everywhere, so a bare 'Teams' / 'Users' assertion would pass vacuously.
    $this->actingAs($admin)->get(route('admin.teams'))->assertOk()
        ->assertSee('Teams isolate connections');
    $this->actingAs($admin)->get(route('admin.users'))->assertOk()
        ->assertSee('Create accounts, grant system-admin');
    $this->actingAs($admin)->get(route('admin.teams.members', $team))->assertOk()->assertSee($team->name);
});
