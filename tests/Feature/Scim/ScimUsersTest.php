<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Scim\ScimUserService;
use App\Services\Users\UserLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiWorld;
use Tests\Support\ScimWorld;

function scimNewUser(array $overrides = []): array
{
    return $overrides + [
        'schemas' => [ScimWorld::USER],
        'userName' => 'ada@example.test',
        'externalId' => 'idp-ada',
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
        'emails' => [['value' => 'ada@example.test', 'type' => 'work', 'primary' => true]],
        'active' => true,
    ];
}

test('a user is created without a password and the create is audited', function () {
    $world = ScimWorld::make();

    $response = $world->call('POST', '/Users', scimNewUser())
        ->assertCreated()
        ->assertHeader('Content-Type', 'application/scim+json')
        ->assertJsonPath('userName', 'ada@example.test')
        ->assertJsonPath('externalId', 'idp-ada')
        ->assertJsonPath('name.formatted', 'Ada Lovelace')
        ->assertJsonPath('emails.0.value', 'ada@example.test')
        ->assertJsonPath('active', true)
        ->assertJsonPath('meta.resourceType', 'User');

    $user = User::where('email', 'ada@example.test')->first();

    expect($user->getAuthPassword())->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->isSuspended())->toBeFalse()
        ->and($user->isScimManaged())->toBeTrue()
        ->and($user->isSsoUser())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe($response->json('meta.location'))
        ->and($response->json('id'))->toBe((string) $user->id);

    $log = AuditLog::where('action', 'scim.user_provisioned')->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->metadata)->toMatchArray(['user' => 'ada@example.test', 'via' => 'scim']);
});

test('a SCIM user cannot be handed a local password by the reset flow', function () {
    $world = ScimWorld::make();
    $world->call('POST', '/Users', scimNewUser())->assertCreated();

    Notification::fake();
    $this->post(route('password.email'), ['email' => 'ada@example.test']);

    Notification::assertNothingSent();
});

test('an existing local account is not taken over: 409 uniqueness', function () {
    $world = ScimWorld::make();
    $local = User::factory()->create(['email' => 'ada@example.test', 'password' => 'secret-password']);

    $world->call('POST', '/Users', scimNewUser(['userName' => 'ADA@example.test']))
        ->assertStatus(409)
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)
        ->assertJsonPath('status', '409')
        ->assertJsonPath('scimType', 'uniqueness');

    $local->refresh();
    expect($local->isScimManaged())->toBeFalse()
        ->and($local->scim_external_id)->toBeNull()
        ->and(User::count())->toBe(1);
});

test('a soft-deleted user still holds its e-mail address', function () {
    $world = ScimWorld::make();
    User::factory()->create(['email' => 'ada@example.test'])->delete();

    $world->call('POST', '/Users', scimNewUser())->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
});

test('a create needs a usable e-mail address', function () {
    $world = ScimWorld::make();

    $world->call('POST', '/Users', ['schemas' => [ScimWorld::USER], 'userName' => 'not-an-email'])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue')->assertJsonPath('schemas.0', ScimWorld::ERROR);
    $world->call('POST', '/Users', ['schemas' => [ScimWorld::USER]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    $world->call('POST', '/Users', ['schemas' => [ScimWorld::USER], 'userName' => 'x', 'emails' => [['value' => 'nope']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    $world->call('POST', '/Users')->assertStatus(400)->assertJsonPath('scimType', 'invalidSyntax');

    expect(User::count())->toBe(0);
});

test('Entra style: userName is the UPN and the work address is in emails', function () {
    $world = ScimWorld::make();

    $world->call('POST', '/Users', scimNewUser(['userName' => 'ada@corp.onmicrosoft.com']))->assertCreated()
        ->assertJsonPath('userName', 'ada@corp.onmicrosoft.com')
        ->assertJsonPath('emails.0.value', 'ada@example.test');

    // The lookup before a create finds it by either name.
    $world->call('GET', '/Users?filter='.urlencode('userName eq "ada@corp.onmicrosoft.com"'))->assertJsonPath('totalResults', 1);
    $world->call('GET', '/Users?filter='.urlencode('userName eq "ada@example.test"'))->assertJsonPath('totalResults', 1);
});

test('users are listed and filtered, and local accounts stay invisible', function () {
    $world = ScimWorld::make();
    $local = User::factory()->create(['email' => 'local@example.test']);
    $first = ScimWorld::scimUser(['email' => 'one@example.test', 'scim_external_id' => 'ext-1']);
    $second = ScimWorld::scimUser(['email' => 'two@example.test']);
    $sso = User::factory()->create(['email' => 'sso@example.test', 'auth_provider' => 'oidc', 'external_issuer' => 'https://idp', 'external_id' => 'sub-1']);

    $world->call('GET', '/Users')->assertOk()
        ->assertJsonPath('totalResults', 3)
        ->assertJsonPath('startIndex', 1)
        ->assertJsonPath('itemsPerPage', 3)
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:ListResponse');

    $world->call('GET', '/Users?startIndex=2&count=1')
        ->assertJsonPath('totalResults', 3)
        ->assertJsonPath('startIndex', 2)
        ->assertJsonPath('itemsPerPage', 1)
        ->assertJsonPath('Resources.0.id', (string) $second->id);

    $world->call('GET', '/Users?count=0')->assertJsonPath('itemsPerPage', 0)->assertJsonPath('totalResults', 3);

    $world->call('GET', '/Users?filter='.urlencode('userName eq "ONE@example.test"'))
        ->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.id', (string) $first->id);
    $world->call('GET', '/Users?filter='.urlencode('externalId eq "ext-1"'))
        ->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.id', (string) $first->id);
    $world->call('GET', '/Users?filter='.urlencode('userName eq "local@example.test"'))->assertJsonPath('totalResults', 0);
    $world->call('GET', '/Users?filter='.urlencode('userName eq "sso@example.test"'))->assertJsonPath('totalResults', 1);

    $world->call('GET', '/Users?filter='.urlencode('userName co "x"'))
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
    $world->call('GET', '/Users?filter='.urlencode('title eq "x"'))
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');

    $world->call('GET', "/Users/{$first->id}")->assertOk()->assertJsonPath('userName', 'one@example.test');
    $world->call('GET', "/Users/{$local->id}")->assertNotFound()
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)->assertJsonPath('status', '404');
    $world->call('GET', '/Users/999999')->assertNotFound();
    $world->call('GET', '/Users/abc')->assertNotFound();
});

test('PATCH active=false (Entra style) shuts the user out of the web and the API', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser(['email' => 'ada@example.test', 'auth_provider' => 'oidc', 'external_issuer' => 'https://idp', 'external_id' => 'sub-1']);
    // A password too, as an account linked after a local sign-up would have.
    $user->forceFill(['password' => 'secret-password'])->save();

    $apiToken = ApiWorld::token($user);
    DB::table('sessions')->insert([
        'id' => 'scim-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    ApiWorld::as($apiToken)->getJson('/api/v1/connections')->assertOk();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'Replace', 'path' => 'active', 'value' => 'False']))
        ->assertOk()
        ->assertJsonPath('active', false);

    $user->refresh();

    expect($user->isSuspended())->toBeTrue()
        ->and($user->trashed())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    ApiWorld::as($apiToken)->getJson('/api/v1/connections')->assertUnauthorized();

    // The API call above left the sanctum guard as the default one.
    auth()->shouldUse('web');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password'])->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();

    $log = AuditLog::where('action', 'scim.user_suspended')->sole();
    expect($log->metadata)->toMatchArray(['user' => 'ada@example.test', 'reason' => 'active_false', 'via' => 'scim']);
    expect(AuditLog::where('action', 'user.suspended')->count())->toBe(1);
});

test('PATCH active=true lifts the suspension, and only then', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();
    app(UserLifecycle::class)->suspend($user, byScim: true);

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'value' => ['active' => true]]))
        ->assertOk()->assertJsonPath('active', true);

    expect($user->fresh()->isSuspended())->toBeFalse()
        ->and(AuditLog::where('action', 'scim.user_unsuspended')->count())->toBe(1);

    // Already active: nothing more is recorded.
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => true]))->assertOk();
    expect(AuditLog::where('action', 'scim.user_unsuspended')->count())->toBe(1);
});

test('DELETE suspends the user and does not delete or soft-delete it', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser(['email' => 'gone@example.test']);

    $world->call('DELETE', "/Users/{$user->id}")->assertNoContent();

    $fresh = User::withTrashed()->find($user->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->trashed())->toBeFalse()
        ->and($fresh->deleted_at)->toBeNull()
        ->and($fresh->isSuspended())->toBeTrue()
        ->and(AuditLog::where('action', 'user.deleted')->count())->toBe(0)
        ->and(AuditLog::where('action', 'scim.user_suspended')->sole()->metadata['reason'])->toBe('delete');

    $world->call('GET', "/Users/{$user->id}")->assertOk()->assertJsonPath('active', false);

    // Deleting again is harmless.
    $world->call('DELETE', "/Users/{$user->id}")->assertNoContent();
    expect(AuditLog::where('action', 'scim.user_suspended')->count())->toBe(1);
});

test('SCIM cannot reach a local account', function () {
    $world = ScimWorld::make();
    $admin = User::factory()->create(['is_admin' => true]);

    $world->call('DELETE', "/Users/{$admin->id}")->assertNotFound();
    $world->call('PATCH', "/Users/{$admin->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => false]))->assertNotFound();
    $world->call('PUT', "/Users/{$admin->id}", scimNewUser())->assertNotFound();

    expect($admin->fresh()->isSuspended())->toBeFalse();
});

test('PATCH changes name, e-mail and externalId in the shapes the providers send', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

    // Entra: dotted paths and a filtered emails path.
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(
        ['op' => 'Replace', 'path' => 'name.givenName', 'value' => 'Augusta'],
        ['op' => 'Replace', 'path' => 'emails[type eq "work"].value', 'value' => 'augusta@example.test'],
        ['op' => 'Add', 'path' => 'externalId', 'value' => 'idp-9'],
        ['op' => 'Replace', 'path' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager', 'value' => 'x'],
        ['op' => 'Replace', 'path' => 'title', 'value' => 'Countess'],
    ))->assertOk()
        ->assertJsonPath('name.formatted', 'Augusta Lovelace')
        ->assertJsonPath('emails.0.value', 'augusta@example.test')
        ->assertJsonPath('externalId', 'idp-9');

    // Okta: no path, an object of attributes.
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(
        ['op' => 'replace', 'value' => ['name' => ['givenName' => 'Ada', 'familyName' => 'King'], 'emails' => [['value' => 'ada.king@example.test', 'primary' => true]]]],
    ))->assertOk()
        ->assertJsonPath('name.formatted', 'Ada King')
        ->assertJsonPath('emails.0.value', 'ada.king@example.test');

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'remove', 'path' => 'externalId']))
        ->assertOk()->assertJsonMissingPath('externalId');

    $user->refresh();
    expect($user->email)->toBe('ada.king@example.test')
        ->and($user->scim_external_id)->toBeNull();

    $changes = AuditLog::where('action', 'scim.user_updated')->get();
    expect($changes)->toHaveCount(3)
        ->and($changes->first()->metadata['changed'])->toEqualCanonicalizing(['email', 'name', 'external_id']);
});

test('PATCH to an address another user holds is 409, and a bad body is 400', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();
    User::factory()->create(['email' => 'taken@example.test']);

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'emails', 'value' => [['value' => 'taken@example.test', 'primary' => true]]]))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    $world->call('PATCH', "/Users/{$user->id}", ['schemas' => [ScimWorld::PATCH]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidSyntax');
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'move', 'path' => 'active']))
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidSyntax');
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => 'maybe']))
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
});

test('PUT replaces the attributes and leaves active alone when it is missing', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser(['email' => 'old@example.test', 'scim_external_id' => 'old-ext']);
    app(UserLifecycle::class)->suspend($user, byScim: true);

    $body = scimNewUser(['userName' => 'new@example.test', 'emails' => [['value' => 'new@example.test', 'primary' => true]]]);
    unset($body['active'], $body['externalId']);

    $world->call('PUT', "/Users/{$user->id}", $body)->assertOk()
        ->assertJsonPath('userName', 'new@example.test')
        ->assertJsonPath('name.formatted', 'Ada Lovelace')
        ->assertJsonPath('active', false)
        ->assertJsonMissingPath('externalId');

    $world->call('PUT', "/Users/{$user->id}", ['active' => true, 'userName' => 'new@example.test'])->assertOk()->assertJsonPath('active', true);
});

test('a user created inactive is suspended at once', function () {
    $world = ScimWorld::make();

    $world->call('POST', '/Users', scimNewUser(['active' => false]))->assertCreated()->assertJsonPath('active', false);

    expect(User::where('email', 'ada@example.test')->first()->isSuspended())->toBeTrue();
});

test('the web session of a SCIM-managed user ends with the suspension', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser(['email' => 'web@example.test']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => false]))->assertOk();

    app('auth')->forgetGuards();
    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
});

test('userName is unique on the server', function () {
    $world = ScimWorld::make();
    User::factory()->create(['email' => 'bob@corp.test']);
    $world->call('POST', '/Users', scimNewUser(['userName' => 'upn@corp.test', 'emails' => [['value' => 'one@example.test', 'primary' => true]]]))->assertCreated();

    // Another user's e-mail address, and another user's userName.
    $world->call('POST', '/Users', scimNewUser(['userName' => 'bob@corp.test', 'emails' => [['value' => 'robert@example.test', 'type' => 'work', 'primary' => true]]]))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    $world->call('POST', '/Users', scimNewUser(['userName' => 'UPN@corp.test', 'emails' => [['value' => 'two@example.test', 'primary' => true]]]))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

    $other = $world->call('POST', '/Users', scimNewUser(['userName' => 'two@example.test', 'emails' => [['value' => 'two@example.test', 'primary' => true]]]))->assertCreated()->json('id');
    $world->call('PATCH', "/Users/{$other}", ScimWorld::patch(['op' => 'replace', 'path' => 'userName', 'value' => 'upn@corp.test']))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    $world->call('PUT', "/Users/{$other}", scimNewUser(['userName' => 'bob@corp.test', 'emails' => [['value' => 'two@example.test', 'primary' => true]]]))
        ->assertStatus(409);

    foreach (['upn@corp.test', 'two@example.test'] as $name) {
        $world->call('GET', '/Users?filter='.urlencode("userName eq \"{$name}\""))->assertJsonPath('totalResults', 1);
    }
});

test('SCIM active=false marks the suspension as its own, and active=true lifts it', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => false]))->assertOk();
    expect($user->fresh()->isSuspended())->toBeTrue()->and($user->fresh()->suspended_by_scim)->toBeTrue();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => true]))->assertOk();
    expect($user->fresh()->isSuspended())->toBeFalse()->and($user->fresh()->suspended_by_scim)->toBeFalse();

    $world->call('DELETE', "/Users/{$user->id}")->assertNoContent();
    expect($user->fresh()->isSuspended())->toBeTrue()->and($user->fresh()->suspended_by_scim)->toBeTrue();
});

test('SCIM active=true does not lift a suspension an administrator set', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();
    app(UserLifecycle::class)->suspend($user);

    expect($user->fresh()->suspended_by_scim)->toBeFalse();

    // PATCH, PUT and a created-active round trip all leave it in place.
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => true]))
        ->assertOk()->assertJsonPath('active', false);
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'value' => ['active' => true]]))->assertOk();
    $world->call('PUT', "/Users/{$user->id}", ['schemas' => [ScimWorld::USER], 'userName' => $user->email, 'active' => true])->assertOk()->assertJsonPath('active', false);

    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and(AuditLog::where('action', 'scim.user_unsuspended')->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.unsuspended')->count())->toBe(0);

    // SCIM's own active=false and DELETE do not claim the administrator's suspension either.
    $world->call('DELETE', "/Users/{$user->id}")->assertNoContent();
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => true]))->assertOk();
    expect($user->fresh()->isSuspended())->toBeTrue()->and($user->fresh()->suspended_by_scim)->toBeFalse();

    // The administrator lifts it, and from then on SCIM manages the account again.
    app(UserLifecycle::class)->unsuspend($user->fresh());
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => false]))->assertOk();
    expect($user->fresh()->suspended_by_scim)->toBeTrue();
});

test('an administrator who suspends a user SCIM suspended already takes the suspension over', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();
    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => false]))->assertOk();

    app(UserLifecycle::class)->suspend($user->fresh());
    expect($user->fresh()->isSuspended())->toBeTrue()->and($user->fresh()->suspended_by_scim)->toBeFalse();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => 'replace', 'path' => 'active', 'value' => true]))->assertOk();
    expect($user->fresh()->isSuspended())->toBeTrue();

    // Lifting by hand clears the mark too.
    app(UserLifecycle::class)->suspend($user->fresh(), byScim: true);
    app(UserLifecycle::class)->unsuspend($user->fresh());
    expect($user->fresh()->suspended_by_scim)->toBeFalse();
});

/**
 * Runs $inject once, right after the first read of the user row by id (the
 * lock read of a lifecycle change): a write another request committed after
 * the caller loaded its copy of the user.
 */
function scimInjectAfterUserLock(int $userId, Closure $inject): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $inject) {
        if ($done || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from "users" where "users"."id" = ?')) {
            return;
        }

        $done = true;
        $inject();
    });
}

test('SCIM suspending does not claim an administrator\'s suspension that landed after it looked', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();
    $stale = User::find($user->id);

    // The administrator's suspension commits between SCIM's look and SCIM's write.
    scimInjectAfterUserLock($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['suspended_at' => now(), 'suspended_by_scim' => false]));

    app(ScimUserService::class)->setActive($stale, false, 'active_false');

    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and($user->fresh()->suspended_by_scim)->toBeFalse()
        ->and(AuditLog::where('action', 'user.suspended')->count())->toBe(0)
        ->and(AuditLog::where('action', 'scim.user_suspended')->count())->toBe(0);

    // So the IdP's later active=true leaves it alone.
    app(ScimUserService::class)->setActive($user->fresh(), true, 'active_true');
    expect($user->fresh()->isSuspended())->toBeTrue();
});

test('an administrator suspending a user SCIM suspended a moment ago takes it over, once', function () {
    ScimWorld::make();
    $user = ScimWorld::scimUser();
    $stale = User::find($user->id);

    scimInjectAfterUserLock($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['suspended_at' => now(), 'suspended_by_scim' => true]));

    expect(app(UserLifecycle::class)->suspend($stale))->toBeFalse();

    expect($user->fresh()->suspended_by_scim)->toBeFalse()
        ->and(AuditLog::where('action', 'user.suspended')->count())->toBe(0);
});

test('SCIM active=true does not lift a suspension an administrator took over after it looked', function () {
    ScimWorld::make();
    $user = ScimWorld::scimUser();
    app(UserLifecycle::class)->suspend($user, byScim: true);
    $stale = User::find($user->id);
    expect($stale->suspended_by_scim)->toBeTrue();

    scimInjectAfterUserLock($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['suspended_by_scim' => false]));

    app(ScimUserService::class)->setActive($stale, true, 'active_true');

    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and(AuditLog::where('action', 'scim.user_unsuspended')->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.unsuspended')->count())->toBe(0);
});

test('a new e-mail address cannot be another user\'s userName', function () {
    $world = ScimWorld::make();
    // Entra style: the e-mail address is robert@, the userName (UPN) bob@.
    $bob = $world->call('POST', '/Users', scimNewUser(['userName' => 'bob@corp.test', 'emails' => [['value' => 'robert@example.test', 'type' => 'work', 'primary' => true]]]))
        ->assertCreated()->json('id');

    // Create: bob@corp.test is taken as a userName, so it cannot be a new user's address.
    $world->call('POST', '/Users', scimNewUser(['userName' => 'c-upn', 'emails' => [['value' => 'bob@corp.test', 'primary' => true]]]))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

    // PATCH and PUT: the same for a user who keeps their own userName.
    $c = $world->call('POST', '/Users', scimNewUser(['userName' => 'c-upn', 'emails' => [['value' => 'c@example.test', 'type' => 'work', 'primary' => true]]]))
        ->assertCreated()->json('id');
    $world->call('PATCH', "/Users/{$c}", ScimWorld::patch(['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'bob@corp.test']))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    $world->call('PUT', "/Users/{$c}", scimNewUser(['userName' => 'c-upn', 'emails' => [['value' => 'BOB@corp.test', 'primary' => true]]]))
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

    expect(User::find($c)->email)->toBe('c@example.test');
    $world->call('GET', '/Users?filter='.urlencode('userName eq "bob@corp.test"'))
        ->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.id', (string) $bob);

    // A user may still set their own userName as their address.
    $world->call('PATCH', "/Users/{$bob}", ScimWorld::patch(['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'bob@corp.test']))->assertOk();
});

test('a long given and family name are cut to fit the column', function () {
    $world = ScimWorld::make();

    $world->call('POST', '/Users', scimNewUser(['name' => ['givenName' => str_repeat('a', 200), 'familyName' => str_repeat('b', 200)]]))->assertCreated();

    expect(mb_strlen(User::where('email', 'ada@example.test')->value('name')))->toBeLessThanOrEqual(255);
});

test('filter values take only the JSON escapes and refuse bytes that are not text', function () {
    $world = ScimWorld::make();
    ScimWorld::scimUser(['email' => 'one@example.test']);

    $world->call('GET', '/Users?filter='.urlencode('userName eq "a\\x00b"'))->assertOk()->assertJsonPath('totalResults', 0);
    $world->call('GET', '/Users?filter='.urlencode('userName eq "a\\"b\\\\c"'))->assertOk()->assertJsonPath('totalResults', 0);
    $world->call('GET', '/Users?filter='.urlencode("userName eq \"a\0b\""))->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
    $world->call('GET', '/Users?filter='.rawurlencode('userName eq "a'."\xff".'b"'))->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
});

test('odd request shapes are 400 SCIM errors, not server errors', function () {
    $world = ScimWorld::make();
    $user = ScimWorld::scimUser();

    $world->call('PATCH', "/Users/{$user->id}", ScimWorld::patch(['op' => ['add'], 'path' => 'active', 'value' => true]))
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidSyntax')->assertJsonPath('schemas.0', ScimWorld::ERROR);
    $world->call('POST', '/Users', scimNewUser(['emails' => [['value' => 'a@example.test', 'primary' => [], 'type' => ['x']]]]))->assertCreated();
    $world->call('GET', '/Groups?excludedAttributes[]=members')->assertOk();
    $world->call('GET', '/Users?filter[]=x')->assertOk();
});

test('an unexpected failure under /scim is a SCIM error that does not leak the cause', function () {
    $world = ScimWorld::make();
    app()->bind(ScimUserService::class, fn () => throw new RuntimeException('secret database detail'));

    $world->call('GET', '/Users')
        ->assertStatus(500)
        ->assertJsonPath('schemas.0', ScimWorld::ERROR)
        ->assertJsonPath('status', '500')
        ->assertHeader('Content-Type', 'application/scim+json');

    expect(test()->call('GET', '/scim/v2/Users', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$world->token])->getContent())->not->toContain('secret database detail');
});
