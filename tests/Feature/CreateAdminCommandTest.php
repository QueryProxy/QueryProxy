<?php

use App\Models\User;

it('creates an administrator from options', function () {
    $this->artisan('queryproxy:create-admin', [
        '--name' => 'Ops Admin',
        '--email' => 'ops@example.com',
        '--password' => 'secret-password',
    ])->assertSuccessful();

    $admin = User::where('email', 'ops@example.com')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->name)->toBe('Ops Admin')
        ->and(Hash::check('secret-password', $admin->password))->toBeTrue();
});

it('rejects a duplicate e-mail or a short password', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('queryproxy:create-admin', [
        '--email' => 'taken@example.com',
        '--password' => 'secret-password',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->artisan('queryproxy:create-admin', [
        '--email' => 'fresh@example.com',
        '--password' => 'short',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::where('email', 'fresh@example.com')->exists())->toBeFalse();
});

it('skips creation with --if-none when the instance already has users', function () {
    User::factory()->create();

    $this->artisan('queryproxy:create-admin', [
        '--email' => 'ops@example.com',
        '--password' => 'secret-password',
        '--if-none' => true,
    ])->assertSuccessful();

    expect(User::where('email', 'ops@example.com')->exists())->toBeFalse();
});

it('requires an e-mail and password when run non-interactively', function () {
    $this->artisan('queryproxy:create-admin', ['--no-interaction' => true])->assertFailed();

    expect(User::count())->toBe(0);
});
