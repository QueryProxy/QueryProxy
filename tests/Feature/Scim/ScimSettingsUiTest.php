<?php

use App\Http\Controllers\Auth\OidcController;
use App\Livewire\Profile\ApiTokens;
use App\Livewire\Settings\Scim;
use App\Models\AuditLog;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\User;
use App\Services\Scim\ScimTokenService;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Support\ScimWorld;

/** The token value the browser was handed by the one-off event of the last create() call. */
function dispatchedScimToken($component): ?string
{
    $token = null;

    $component->assertDispatched('scim-token-created', function (string $event, array $params) use (&$token) {
        $token = $params['token'] ?? null;

        return true;
    });

    return $token;
}

function scimAdmin(array $attributes = []): User
{
    return User::factory()->create(['is_admin' => true] + $attributes);
}

function filledScimForm(User $admin, string $name = 'entra-production')
{
    return Livewire::actingAs($admin)
        ->test(Scim::class)
        ->set('name', $name)
        ->set('confirmPassword', 'password');
}

beforeEach(function () {
    config(['queryproxy.scim.enabled' => true]);
});

test('only a system admin reaches the SCIM settings page', function () {
    $dba = User::factory()->create();
    $dba->teams()->attach(Team::factory()->create(), ['role' => 'dba']);

    $this->get(route('settings.scim'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('settings.scim'))->assertForbidden();
    $this->actingAs($dba)->get(route('settings.scim'))->assertForbidden();

    $this->actingAs(scimAdmin())->get(route('settings.scim'))
        ->assertOk()
        ->assertSee('SCIM provisioning')
        ->assertSee(url('/scim/v2'))
        ->assertSeeLivewire(Scim::class);
});

test('the navigation links to the page for admins only', function () {
    $this->actingAs(scimAdmin())->get(route('dashboard'))->assertSee(route('settings.scim'));

    $dba = User::factory()->create();
    $dba->teams()->attach(Team::factory()->create(), ['role' => 'dba']);
    $this->actingAs($dba)->get(route('dashboard'))->assertDontSee(route('settings.scim'));
});

test('a demoted admin cannot create or revoke through a page that was served earlier', function () {
    $admin = scimAdmin();
    [$token] = app(ScimTokenService::class)->create('idp');
    $creating = Livewire::actingAs($admin)->test(Scim::class)->set('name', 'late')->set('confirmPassword', 'password');
    $revoking = Livewire::actingAs($admin)->test(Scim::class);

    $admin->forceFill(['is_admin' => false])->save();

    $creating->call('create')->assertForbidden();
    $revoking->call('revoke', $token->id)->assertForbidden();

    expect(ScimToken::count())->toBe(1)
        ->and($token->fresh()->revoked_at)->toBeNull();
});

test('while SCIM is off the page explains it and offers no form, and no token can be created', function () {
    config(['queryproxy.scim.enabled' => false]);

    $component = Livewire::actingAs(scimAdmin())
        ->test(Scim::class)
        ->assertSee('SCIM is switched off')
        ->assertSee('QUERYPROXY_SCIM_ENABLED')
        ->assertDontSee('Create token')
        ->assertDontSee('Confirm with your password');

    $component->set('name', 'sneaky')->set('confirmPassword', 'password')->call('create')
        ->assertHasErrors('name')
        ->assertNotDispatched('scim-token-created');

    expect(ScimToken::count())->toBe(0);
});

test('a token is created with a name and a fresh confirmation, and its value is handed over once', function () {
    $admin = scimAdmin();

    $component = filledScimForm($admin)->call('create')->assertHasNoErrors();

    $plain = dispatchedScimToken($component);
    $stored = ScimToken::sole();

    expect($plain)->toStartWith('qpscim_')
        ->and($stored->name)->toBe('entra-production')
        ->and($stored->created_by)->toBe($admin->id)
        ->and($stored->token_hash)->toBe(ScimToken::hash($plain))
        ->and(AuditLog::where('action', 'scim.token_created')->count())->toBe(1)
        ->and(json_encode(AuditLog::all()->toArray()))->not->toContain($plain);

    // The value is not a property and not in what the page renders; the form is empty again.
    $component->assertDontSee($plain)->assertSee('entra-production')->assertSet('name', '');
    expect(json_encode($component->getData()))->not->toContain($plain);

    // Nor does it come back with the next interaction or a fresh page load.
    $component->set('name', 'something else');
    expect($component->html())->not->toContain($plain);

    $this->actingAs($admin)->get(route('settings.scim'))->assertOk()->assertDontSee($plain);

    Livewire::actingAs($admin)->test(Scim::class)->assertNotDispatched('scim-token-created')->assertDontSee($plain);
});

test('a created token works against the SCIM endpoint until it is revoked, then gets 401', function () {
    $admin = scimAdmin();

    $component = filledScimForm($admin, 'zz-idp-7f3a')->call('create');
    $plain = dispatchedScimToken($component);
    $world = new ScimWorld;

    $world->call('GET', '/ServiceProviderConfig', null, $plain)->assertOk();

    $component->call('revoke', ScimToken::sole()->id)
        ->assertSee('Token revoked')
        ->assertSee('Revoked');

    expect(ScimToken::sole()->revoked_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'scim.token_revoked')->count())->toBe(1);

    $world->call('GET', '/ServiceProviderConfig', null, $plain)->assertUnauthorized();

    // A second press (another tab) is harmless and writes no second audit row.
    $component->call('revoke', ScimToken::sole()->id)->assertSee('already revoked');
    expect(AuditLog::where('action', 'scim.token_revoked')->count())->toBe(1);

    $component->call('revoke', 999999)->assertSee('already gone');
});

test('the list shows who made a token, its state and when it was last used', function () {
    $admin = scimAdmin(['name' => 'Ada Admin']);
    [$live] = app(ScimTokenService::class)->create('live idp', $admin);
    [$old] = app(ScimTokenService::class)->create('old idp');
    app(ScimTokenService::class)->revoke($old);
    $live->forceFill(['last_used_at' => now()->subHour()])->save();

    Livewire::actingAs($admin)->test(Scim::class)
        ->assertSee('live idp')
        ->assertSee('Ada Admin')
        ->assertSee('1 hour ago')
        ->assertSee('old idp')
        ->assertSee('the command line')
        ->assertSee('never used')
        ->assertSeeInOrder(['old idp', 'Revoked'])
        ->assertSee('Revoke SCIM token live idp')
        ->assertDontSee('Revoke SCIM token old idp');
});

test('an empty list says so', function () {
    Livewire::actingAs(scimAdmin())->test(Scim::class)->assertSee('No SCIM tokens yet.');
});

test('a wrong password creates nothing and does not hand over a value', function () {
    Livewire::actingAs(scimAdmin())
        ->test(Scim::class)
        ->set('name', 'idp')
        ->set('confirmPassword', 'wrong')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('scim-token-created')
        ->assertSet('confirmPassword', '');

    expect(ScimToken::count())->toBe(0);
});

test('a bad name is reported before the confirmation is spent', function () {
    foreach (['', '   ', str_repeat('x', 101)] as $name) {
        filledScimForm(scimAdmin(), $name)->call('create')
            ->assertHasErrors('name')
            ->assertHasNoErrors('confirmation')
            ->assertNotDispatched('scim-token-created');
    }

    expect(ScimToken::count())->toBe(0);
});

test('too many wrong confirmations lock the form for a while', function () {
    $admin = scimAdmin();

    for ($i = 0; $i < 5; $i++) {
        Livewire::actingAs($admin)->test(Scim::class)
            ->set('name', 'idp')->set('confirmPassword', 'wrong')->call('create')
            ->assertHasErrors('confirmation');
    }

    filledScimForm($admin)->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('scim-token-created');

    expect(ScimToken::count())->toBe(0);
});

test('wrong confirmations are counted per user across the API tokens and SCIM screens', function () {
    $admin = scimAdmin();

    // Three wrong tries on the profile screen and two on the SCIM screen use up the one budget of five.
    for ($i = 0; $i < 3; $i++) {
        Livewire::actingAs($admin)->test(ApiTokens::class)
            ->set('name', 'pat')->set('scopes', ['requests:read'])->set('confirmPassword', 'wrong')->call('create')
            ->assertHasErrors('confirmation');
    }

    for ($i = 0; $i < 2; $i++) {
        Livewire::actingAs($admin)->test(Scim::class)
            ->set('name', 'idp')->set('confirmPassword', 'wrong')->call('create')
            ->assertHasErrors('confirmation');
    }

    // The right password is now refused on either screen.
    filledScimForm($admin)->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('scim-token-created');

    Livewire::actingAs($admin)->test(ApiTokens::class)
        ->set('name', 'pat')->set('scopes', ['requests:read'])->set('confirmPassword', 'password')->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('api-token-created');

    expect(ScimToken::count())->toBe(0);
});

test('the SSO re-sign-in button returns to this page and a refused attempt shows its error here', function () {
    $sso = scimAdmin(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-admin']);

    // A refused confirmation comes back with an "sso" error in the session (OidcController).
    session(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['sso' => ['Single sign-on failed.']]))]);

    Livewire::actingAs($sso)->test(Scim::class)
        ->assertSee('Single sign-on failed.')
        ->assertSee(route('oidc.reauthenticate', ['return' => 'scim']), false);
});

test('the copy buttons have distinct accessible names', function () {
    Livewire::actingAs(scimAdmin())->test(Scim::class)
        ->assertSee('Copy the SCIM endpoint URL')
        ->assertSee('Copy the new SCIM token');
});

test('an admin without a local password confirms through a fresh SSO sign-in', function () {
    $sso = scimAdmin(['password' => null, 'auth_provider' => 'oidc', 'external_id' => 'sub-admin']);

    $form = fn () => Livewire::actingAs($sso)->test(Scim::class)->set('name', 'idp');

    // No SSO flag in the session: refused, and the screen offers the SSO round trip.
    $form()->assertSee('Sign in with SSO again')
        ->assertDontSee('Confirm with your password')
        ->call('create')
        ->assertHasErrors('confirmation')
        ->assertNotDispatched('scim-token-created');

    // Signed in through SSO, but too long ago.
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->subMinutes(16)->getTimestamp()]);
    $form()->call('create')->assertHasErrors('confirmation');

    expect(ScimToken::count())->toBe(0);

    // Fresh.
    session([OidcController::SESSION_FLAG => true, OidcController::SESSION_AT_KEY => now()->subMinutes(14)->getTimestamp()]);
    $form()->assertDontSee('Sign in with SSO again')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('scim-token-created');

    expect(ScimToken::count())->toBe(1);
});
