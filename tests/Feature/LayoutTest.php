<?php

use App\Models\Team;
use App\Models\User;

test('the guest layout ships the theme bootstrap and the webfonts', function () {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertSee('qp-theme', escape: false)
        ->assertSee('IBM Plex Sans', escape: false);
});

test('the app layout ships the theme bootstrap and the webfonts', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user, ['role' => 'developer']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk()
        ->assertSee('qp-theme', escape: false)
        ->assertSee('IBM Plex Sans', escape: false);
});

test('dark is the default theme, so no attribute is rendered', function () {
    $this->get('/login')->assertDontSee('data-theme=', escape: false);
});
