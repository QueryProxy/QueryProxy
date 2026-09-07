<?php

use App\Livewire\Profile;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

test('a user with 2FA enabled is challenged instead of logged straight in', function () {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey();

    $user = User::factory()->create(['password' => 'secret-password']);
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('two-factor.challenge'));

    expect(auth()->check())->toBeFalse();

    // Wrong code keeps the session unauthenticated.
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    expect(auth()->check())->toBeFalse();

    // The correct current code completes the login.
    $this->post('/two-factor-challenge', ['code' => $engine->getCurrentOtp($secret)])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

test('a single-use recovery code logs in and is then consumed', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();

    $user = User::factory()->create(['password' => 'secret-password']);
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $codes = $service->issueRecoveryCodes($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and($service->consumeRecoveryCode($user->fresh(), $codes[0]))->toBeFalse();
});

test('a user without 2FA logs in directly', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

test('enrollment requires a valid code and issues recovery codes', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Profile::class)
        ->call('startTwoFactorEnrollment');

    $secret = session('two_factor_setup_secret');
    expect($secret)->not->toBeNull();

    // Wrong code does not enable 2FA.
    $component->set('twoFactorCode', '000000')->call('confirmTwoFactor')->assertHasErrors('twoFactorCode');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    // Correct code enables it and produces recovery codes.
    $otp = (new Google2FA)->getCurrentOtp($secret);
    $component->set('twoFactorCode', $otp)->call('confirmTwoFactor')->assertHasNoErrors();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(8);
});
