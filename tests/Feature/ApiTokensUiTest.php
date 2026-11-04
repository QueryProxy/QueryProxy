<?php

use App\Http\Controllers\Auth\OidcController;
use App\Livewire\Profile;
use App\Livewire\Profile\ApiTokens;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Api\TokenService;
use App\Services\Auth\TwoFactorService;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ApiWorld;

/** The token value the browser was handed by the one-off event of the last create() call. */
function dispatchedToken($component): ?string
{
    $token = null;

    $component->assertDispatched('api-token-created', function (string $event, array $params) use (&$token) {
        $token = $params['token'] ?? null;

        return true;
    });

    return $token;
}

test('the profile page carries the API tokens section', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile'))
        ->assertOk()
        ->assertSee('API tokens')
        ->assertSeeLivewire(ApiTokens::class);
});

test('a token is created with a name, scopes and an expiry, and its value is handed over once', function () {
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('name', 'nightly job')
        ->set('scopes', ['requests:read', 'requests:submit'])
        ->set('days', '14')
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasNoErrors();

    $plain = dispatchedToken($component);
    $stored = PersonalAccessToken::sole();

    expect($plain)->toBeString()->toContain('|')
        ->and($stored->name)->toBe('nightly job')
        ->and($stored->abilities)->toBe(['requests:read', 'requests:submit'])
        ->and((int) round(now()->diffInDays($stored->expires_at)))->toBe(14)
        ->and(AuditLog::where('action', 'token.created')->count())->toBe(1);

    // The value is not a property, not in the rendered page and not in the audit trail.
    $component->assertDontSee($plain)->assertSee('nightly job');
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain(explode('|', $plain)[1]);
});

test('the token value does not come back after the next interaction or a page reload', function () {
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('name', 'once')
        ->set('scopes', ['requests:read'])
        ->set('confirmPassword', 'password')
        ->call('create');

    $plain = dispatchedToken($component);

    // Anything else the user does afterwards: the value is nowhere in the component state.
    $component->set('name', 'something else');
    expect(json_encode($component->getData()))->not->toContain($plain)
        ->and($component->html())->not->toContain($plain);

    // A reload mounts the component afresh.
    $this->actingAs($world->developer)->get(route('profile'))
        ->assertOk()
        ->assertDontSee($plain)
        ->assertDontSee(explode('|', $plain)[1]);

    Livewire::actingAs($world->developer)->test(ApiTokens::class)
        ->assertNotDispatched('api-token-created')
        ->assertDontSee($plain);
});

test('a token from the screen works against the API until it is revoked, then gets 401', function () {
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('name', 'zz-pipeline-7f3a')
        ->set('scopes', ['requests:read'])
        ->set('confirmPassword', 'password')
        ->call('create');

    $plain = dispatchedToken($component);

    ApiWorld::as($plain)->getJson('/api/v1/connections')->assertOk();

    $component->call('revoke', PersonalAccessToken::sole()->id)
        ->assertSee('Token revoked')
        ->assertDontSee('zz-pipeline-7f3a');

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(AuditLog::where('action', 'token.revoked')->count())->toBe(1);

    ApiWorld::as($plain)->getJson('/api/v1/connections')->assertUnauthorized();
});

test('a user without the DBA role is not offered approvals:write and cannot smuggle it in', function () {
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->assertSee('requests:submit')
        ->assertDontSee('approvals:write')
        ->set('name', 'sneaky')
        ->set('scopes', ['approvals:write'])
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('scopes')
        ->assertNotDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(0);

    // An admin who is not a DBA anywhere can only override on the web: no approvals:write either.
    Livewire::actingAs($world->admin)->test(ApiTokens::class)->assertDontSee('approvals:write');
});

test('a DBA is offered every scope', function () {
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->dba)->test(ApiTokens::class);

    foreach (TokenService::scopes() as $scope) {
        $component->assertSee($scope);
    }
});

test('an auditor is offered neither submit, results nor approvals', function () {
    $world = ApiWorld::make();

    Livewire::actingAs($world->auditor)
        ->test(ApiTokens::class)
        ->assertSee('requests:read')
        ->assertDontSee('requests:submit')
        ->assertDontSee('results:read')
        ->assertDontSee('approvals:write');
});

test('the lifetime is required and capped by the configured maximum', function () {
    config(['queryproxy.api.token_max_days' => 30]);
    $world = ApiWorld::make();

    $component = Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->assertSet('days', '30')
        ->set('name', 'long')
        ->set('scopes', ['requests:read']);

    foreach (['31', '0', '', 'abc'] as $days) {
        $component->set('days', $days)->set('confirmPassword', 'password')
            ->call('create')->assertHasErrors('days');
    }

    expect(PersonalAccessToken::count())->toBe(0);

    $component->set('days', '30')->set('confirmPassword', 'password')
        ->call('create')->assertHasNoErrors();

    expect(PersonalAccessToken::count())->toBe(1);
});

test('the name and at least one scope are required', function () {
    $world = ApiWorld::make();

    Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('name', '   ')
        ->set('scopes', ['requests:read'])
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('name')
        ->set('name', 'x')
        ->set('scopes', [])
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('scopes');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('the list shows only the own tokens, with expiry and last use, and an expired one is marked', function () {
    $world = ApiWorld::make();
    $mine = $world->developer->createToken('mine', ['requests:read'], now()->addDays(10));
    $old = $world->developer->createToken('stale', ['requests:read'], now()->subDay());
    $other = $world->dba->createToken('theirs', ['requests:read'], now()->addDays(10));
    $mine->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();

    Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->assertSee('mine')
        ->assertSee('stale')
        ->assertSee('Expired')
        ->assertSee('last used')
        ->assertSee('never used')
        ->assertDontSee('theirs');
});

test('a user cannot revoke somebody else\'s token', function () {
    $world = ApiWorld::make();
    $theirs = $world->dba->createToken('theirs', ['requests:read'], now()->addDays(10));

    Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->call('revoke', $theirs->accessToken->id)
        ->assertSee('already gone');

    expect(PersonalAccessToken::count())->toBe(1)
        ->and(AuditLog::where('action', 'token.revoked')->count())->toBe(0);
});

test('an empty list says so', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ApiTokens::class)
        ->assertSee('You have no API tokens.');
});

test('the existing profile sections still work next to the tokens section', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'Renamed')
        ->call('updateName')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Renamed');
});

/** A form that is otherwise valid, for the confirmation tests. */
function filledTokenForm(User $user)
{
    return Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'confirmed job')
        ->set('scopes', ['requests:read']);
}

test('creating a token needs the local password, and a wrong or missing one makes nothing', function () {
    $world = ApiWorld::make();

    filledTokenForm($world->developer)
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('api-token-created')
        ->set('confirmPassword', 'not-my-password')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertSet('confirmPassword', '')
        ->assertNotDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(AuditLog::where('action', 'token.created')->count())->toBe(0);
});

test('guessing the confirmation password is rate limited, even with the right one afterwards', function () {
    $world = ApiWorld::make();
    $component = filledTokenForm($world->developer);

    foreach (range(1, 5) as $attempt) {
        $component->set('confirmPassword', 'wrong-'.$attempt)->call('create')->assertHasErrors('confirmation');
    }

    $component->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('the password stays out of the component state after the attempt', function () {
    $world = ApiWorld::make();

    $component = filledTokenForm($world->developer)->set('confirmPassword', 'password')->call('create');

    $component->assertSet('confirmPassword', '')->assertSet('confirmCode', '');
});

test('a form mistake is reported before the confirmation is spent', function () {
    $world = ApiWorld::make();

    Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('scopes', ['requests:read'])
        ->set('confirmPassword', 'not-my-password')
        ->call('create')
        ->assertHasErrors('name')
        ->assertHasNoErrors('confirmation');
});

test('with two-factor authentication on, a current authenticator code confirms instead of the password', function () {
    $world = ApiWorld::make();
    $secret = app(TwoFactorService::class)->generateSecret();
    $world->developer->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

    $component = filledTokenForm($world->developer)
        ->assertSee('authenticator app')
        ->set('confirmCode', '000000')
        ->call('create')
        ->assertHasErrors('confirmation');

    expect(PersonalAccessToken::count())->toBe(0);

    $component->set('confirmCode', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(1);
});

test('an authenticator code is not accepted from a user who has not turned two-factor on', function () {
    $world = ApiWorld::make();
    $secret = app(TwoFactorService::class)->generateSecret();
    $world->developer->forceFill(['two_factor_secret' => $secret])->save(); // enrolment never confirmed

    filledTokenForm($world->developer)
        ->assertDontSee('authenticator app')
        ->set('confirmCode', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('create')
        ->assertHasErrors('confirmation');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('a user without a local password needs an SSO sign-in from the last 15 minutes', function () {
    $sso = User::factory()->create(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-x']);
    $sso->teams()->attach(Team::factory()->create(), ['role' => 'developer']);

    // No SSO flag in the session: refused, and the screen offers the SSO round trip.
    filledTokenForm($sso)
        ->assertSee('Sign in with SSO again')
        ->assertDontSee('Confirm with your password')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('api-token-created');

    // Signed in through SSO, but too long ago.
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->subMinutes(16)->getTimestamp()]);
    filledTokenForm($sso)->call('create')->assertHasErrors('confirmation');

    // A flag without a time (a session from before this check) does not count either.
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => null]);
    filledTokenForm($sso)->call('create')->assertHasErrors('confirmation');

    expect(PersonalAccessToken::count())->toBe(0);

    // Fresh.
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->subMinutes(14)->getTimestamp()]);
    filledTokenForm($sso)
        ->assertDontSee('Sign in with SSO again')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(1);
});

test('typing a password does not help a user who has none', function () {
    $sso = User::factory()->create(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-y']);

    filledTokenForm($sso)->set('confirmPassword', 'anything')->call('create')->assertHasErrors('confirmation');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('an SSO user who also has a local password confirms with it', function () {
    $linked = User::factory()->create(['auth_provider' => 'oidc', 'external_id' => 'sub-z']);

    filledTokenForm($linked)
        ->assertSee('Confirm with your password')
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('api-token-created');
});

test('an admin is offered results:read', function () {
    Livewire::actingAs(ApiWorld::make()->admin)->test(ApiTokens::class)->assertSee('results:read');
});

test('a suspended user gets an error and no token', function () {
    $world = ApiWorld::make();
    $world->developer->forceFill(['suspended_at' => now()])->save();

    filledTokenForm($world->developer)
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('name')
        ->assertNotDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(0);
});

test('a user the 2FA requirement covers cannot create a token before enrolling, password or not', function (string $policy, string $who) {
    config(['queryproxy.require_two_factor' => $policy]);
    $world = ApiWorld::make();

    filledTokenForm($world->{$who})
        ->assertSee('requires two-factor authentication')
        ->assertDontSee('Create token')
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('api-token-created');

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'dba under "dba"' => ['dba', 'dba'],
    'developer under "all"' => ['all', 'developer'],
    'admin under "admins"' => ['admins', 'admin'],
]);

test('the same user creates a token once enrolled, and a user outside the requirement is not held back', function () {
    config(['queryproxy.require_two_factor' => 'dba']);
    $world = ApiWorld::make();

    // A developer is outside "dba".
    filledTokenForm($world->developer)->set('confirmPassword', 'password')->call('create')->assertDispatched('api-token-created');

    $world->dba->forceFill([
        'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
        'two_factor_confirmed_at' => now(),
    ])->save();

    filledTokenForm($world->dba)->set('confirmPassword', 'password')->call('create')->assertDispatched('api-token-created');
});

test('an SSO account is outside the requirement, as for the middleware', function () {
    config(['queryproxy.require_two_factor' => 'all']);
    $sso = User::factory()->create(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-q']);
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->getTimestamp()]);

    filledTokenForm($sso)->call('create')->assertHasNoErrors()->assertDispatched('api-token-created');
});

test('an SSO account that still has a local password is covered even in an SSO session, as the API covers its tokens', function () {
    config(['queryproxy.require_two_factor' => 'all']);
    $linked = User::factory()->create(['auth_provider' => 'oidc', 'external_id' => 'sub-r']);

    foreach ([[], [OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->getTimestamp()]] as $session) {
        session($session);

        filledTokenForm($linked)
            ->assertSee('requires two-factor authentication')
            ->set('confirmPassword', 'password')
            ->call('create')
            ->assertHasErrors('confirmation')
            ->assertNotDispatched('api-token-created');
    }

    expect(PersonalAccessToken::count())->toBe(0);

    // Enrolled, it creates a token, and the API accepts it.
    $linked->forceFill(['two_factor_secret' => app(TwoFactorService::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();

    $component = filledTokenForm($linked)->set('confirmPassword', 'password')->call('create')->assertDispatched('api-token-created');

    ApiWorld::as(dispatchedToken($component))->getJson('/api/v1/connections')->assertOk();
});

test('whatever the screen lets a user create, the API accepts', function (string $policy) {
    config(['queryproxy.require_two_factor' => $policy]);
    $world = ApiWorld::make();
    $linkedAdmin = User::factory()->create(['auth_provider' => 'oidc', 'external_id' => 'sub-v', 'is_admin' => true]);
    $ssoOnly = User::factory()->create(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-w']);
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->getTimestamp()]);

    foreach ([$world->developer, $world->dba, $world->admin, $linkedAdmin, $ssoOnly] as $user) {
        $component = filledTokenForm($user)->set('confirmPassword', 'password')->call('create');
        $plain = null;

        try {
            $plain = dispatchedToken($component);
        } catch (Throwable) {
            // Refused by the screen: nothing to hand over.
        }

        if ($plain !== null) {
            ApiWorld::as($plain)->getJson('/api/v1/connections')->assertSuccessful();
        }
    }
})->with(['none', 'admins', 'dba', 'all']);

test('the form keeps no password in the component state when it is refused for a mistake in the form', function () {
    $world = ApiWorld::make();

    Livewire::actingAs($world->developer)
        ->test(ApiTokens::class)
        ->set('scopes', ['requests:read'])
        ->set('confirmPassword', 'password')
        ->call('create')
        ->assertHasErrors('name')
        ->assertSet('confirmPassword', '');
});
