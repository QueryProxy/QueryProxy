<?php

use App\Livewire\Admin\Users;
use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

test('users can change their own password from the profile page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('currentPassword', 'password')
        ->set('password', 'new-password-123')
        ->set('password_confirmation', 'new-password-123')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

test('a wrong current password is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('currentPassword', 'not-my-password')
        ->set('password', 'new-password-123')
        ->set('password_confirmation', 'new-password-123')
        ->call('updatePassword')
        ->assertHasErrors('currentPassword');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('users can update their name from the profile page', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'New Name')
        ->call('updateName')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name');
});

test('admins can reset another users password', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $oldHash = $user->password;

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('resetPassword', $user->id)
        ->assertHasNoErrors();

    // The generated password is flashed for one-time display, never kept in a
    // public property (which would round-trip through every Livewire request).
    expect(property_exists(Users::class, 'generatedPassword'))->toBeFalse()
        ->and($user->fresh()->password)->not->toBe($oldHash);
});

test('admins cannot reset their own password from the users page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $oldHash = $admin->password;

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('resetPassword', $admin->id)
        ->assertHasErrors('email');

    expect($admin->fresh()->password)->toBe($oldHash);
});

test('a reset link is sent for known accounts and the response never enumerates', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status');

    $this->post(route('password.email'), ['email' => 'ghost@example.com'])
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

test('a valid token resets the password', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertRedirect(route('login'));

    expect(Hash::check('brand-new-pass-1', $user->fresh()->password))->toBeTrue();

    $this->post('/login', ['email' => $user->email, 'password' => 'brand-new-pass-1'])
        ->assertRedirect('/dashboard');
});

test('an invalid token does not reset the password', function () {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => 'bogus-token',
        'email' => $user->email,
        'password' => 'brand-new-pass-1',
        'password_confirmation' => 'brand-new-pass-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('the forgot and reset pages render', function () {
    $this->get(route('password.request'))->assertOk()->assertSee('reset link');
    $this->get(route('password.reset', 'some-token'))->assertOk()->assertSee('new password');
});
