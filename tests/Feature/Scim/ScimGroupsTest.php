<?php

use App\Models\AuditLog;
use App\Models\ScimGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScimWorld;

/** @return array{0: ScimWorld, 1: Team, 2: User} */
function scimGroupSetup(): array
{
    $world = ScimWorld::make();
    config(['queryproxy.oidc.role_map' => 'platform-dba:payments:dba,platform-dev:payments:developer']);

    $team = Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']);
    $user = ScimWorld::scimUser(['email' => 'ada@example.test']);

    return [$world, $team, $user];
}

function scimRoleOf(User $user, Team $team): ?string
{
    return $user->teams()->where('teams.id', $team->id)->first()?->pivot->role;
}

test('a group is created with members and gives the mapped team role', function () {
    [$world, $team, $user] = scimGroupSetup();

    $world->call('POST', '/Groups', [
        'schemas' => [ScimWorld::GROUP],
        'displayName' => 'platform-dba',
        'externalId' => 'grp-1',
        'members' => [['value' => (string) $user->id]],
    ])->assertCreated()
        ->assertHeader('Content-Type', 'application/scim+json')
        ->assertJsonPath('displayName', 'platform-dba')
        ->assertJsonPath('members.0.value', (string) $user->id)
        ->assertJsonPath('meta.resourceType', 'Group');

    expect(scimRoleOf($user, $team))->toBe('dba');

    $sync = AuditLog::where('action', 'scim.group_synced')->sole();
    expect($sync->metadata)->toMatchArray(['operation' => 'created', 'group' => 'platform-dba', 'members_added' => 1, 'via' => 'scim']);

    $role = AuditLog::where('action', 'user.role_synced')->sole();
    expect($role->user_id)->toBeNull()
        ->and($role->metadata)->toMatchArray(['change' => 'added', 'to' => 'dba', 'via' => 'scim']);

    // The group lists the user it holds.
    $world->call('GET', "/Users/{$user->id}")->assertJsonPath('groups.0.display', 'platform-dba');
});

test('PATCH members add, remove and replace move the team role', function () {
    [$world, $team, $user] = scimGroupSetup();
    $other = ScimWorld::scimUser(['email' => 'grace@example.test']);

    $dba = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba'])->assertCreated()->json('id');
    $dev = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dev'])->assertCreated()->json('id');

    // Entra: add by path
    $world->call('PATCH', "/Groups/{$dba}", ScimWorld::patch(['op' => 'Add', 'path' => 'members', 'value' => [['value' => (string) $user->id], ['value' => (string) $other->id]]]))
        ->assertOk()->assertJsonCount(2, 'members');
    expect(scimRoleOf($user, $team))->toBe('dba')->and(scimRoleOf($other, $team))->toBe('dba');

    // Entra: remove one by filter
    $world->call('PATCH', "/Groups/{$dba}", ScimWorld::patch(['op' => 'Remove', 'path' => "members[value eq \"{$other->id}\"]"]))
        ->assertOk()->assertJsonCount(1, 'members');
    expect(scimRoleOf($other, $team))->toBeNull()->and(scimRoleOf($user, $team))->toBe('dba');

    // The user also joins the developer group: the highest role wins.
    $world->call('PATCH', "/Groups/{$dev}", ScimWorld::patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]))->assertOk();
    expect(scimRoleOf($user, $team))->toBe('dba');

    // Okta: remove lists the members
    $world->call('PATCH', "/Groups/{$dba}", ScimWorld::patch(['op' => 'remove', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]))->assertOk();
    expect(scimRoleOf($user, $team))->toBe('developer');

    // replace
    $world->call('PATCH', "/Groups/{$dev}", ScimWorld::patch(['op' => 'replace', 'path' => 'members', 'value' => [['value' => (string) $other->id]]]))->assertOk();
    expect(scimRoleOf($user, $team))->toBeNull()->and(scimRoleOf($other, $team))->toBe('developer');

    // remove with no value empties the group
    $world->call('PATCH', "/Groups/{$dev}", ScimWorld::patch(['op' => 'remove', 'path' => 'members']))->assertOk()->assertJsonCount(0, 'members');
    expect(scimRoleOf($other, $team))->toBeNull();

    expect(AuditLog::where('action', 'scim.group_synced')->count())->toBe(2 + 6);
});

test('only the teams the map names are touched', function () {
    [$world, $team, $user] = scimGroupSetup();
    $unmapped = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $unmapped->users()->attach($user, ['role' => 'auditor']);

    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => [['value' => (string) $user->id]]])->json('id');
    $world->call('DELETE', "/Groups/{$id}")->assertNoContent();

    expect(scimRoleOf($user, $team))->toBeNull()
        ->and(scimRoleOf($user, $unmapped))->toBe('auditor');
});

test('a group the map does not name is accepted and changes no role', function () {
    [$world, $team, $user] = scimGroupSetup();

    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'cafeteria', 'members' => [['value' => (string) $user->id]]])->assertCreated();

    expect(scimRoleOf($user, $team))->toBeNull()
        ->and(ScimGroup::count())->toBe(1)
        ->and(AuditLog::where('action', 'user.role_synced')->count())->toBe(0);
});

test('renaming a group moves its members\' roles', function () {
    [$world, $team, $user] = scimGroupSetup();
    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => [['value' => (string) $user->id]]])->json('id');

    $world->call('PATCH', "/Groups/{$id}", ScimWorld::patch(['op' => 'replace', 'path' => 'displayName', 'value' => 'platform-dev']))
        ->assertOk()->assertJsonPath('displayName', 'platform-dev');

    expect(scimRoleOf($user, $team))->toBe('developer');
});

test('PUT replaces a group', function () {
    [$world, $team, $user] = scimGroupSetup();
    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => [['value' => (string) $user->id]]])->json('id');

    $world->call('PUT', "/Groups/{$id}", ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => []])
        ->assertOk()->assertJsonCount(0, 'members');

    expect(scimRoleOf($user, $team))->toBeNull();
});

test('group errors have the SCIM shape', function () {
    [$world, , $user] = scimGroupSetup();
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'Platform-DBA'])->assertCreated();
    $local = User::factory()->create();

    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba'])
        ->assertStatus(409)->assertJsonPath('scimType', 'uniqueness')->assertJsonPath('schemas.0', ScimWorld::ERROR);
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'g2', 'members' => [['value' => '999999']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    // A local account is not a member SCIM may name.
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'g2', 'members' => [['value' => (string) $local->id]]])
        ->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    $world->call('GET', '/Groups/999999')->assertNotFound()->assertJsonPath('status', '404');
    $world->call('DELETE', '/Groups/999999')->assertNotFound();
    $world->call('PATCH', '/Groups/999999', ScimWorld::patch(['op' => 'add', 'path' => 'members', 'value' => []]))->assertNotFound();

    expect(ScimGroup::count())->toBe(1)->and($user->refresh()->teams)->toHaveCount(0);
});

test('groups are listed, filtered and can leave the members out', function () {
    [$world, , $user] = scimGroupSetup();
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => [['value' => (string) $user->id]]]);
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dev']);

    $world->call('GET', '/Groups')->assertOk()->assertJsonPath('totalResults', 2)->assertJsonCount(1, 'Resources.0.members');
    $world->call('GET', '/Groups?filter='.urlencode('displayName eq "PLATFORM-DEV"'))
        ->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.displayName', 'platform-dev');
    $world->call('GET', '/Groups?excludedAttributes=members')->assertJsonMissingPath('Resources.0.members');
    $world->call('GET', '/Groups?startIndex=2&count=1')->assertJsonPath('Resources.0.displayName', 'platform-dev');
});

test('a group the map does not name never touches the roles of mapped teams', function () {
    [$world, $team, $user] = scimGroupSetup();
    // The role came from an SSO login, not from SCIM.
    $team->users()->attach($user, ['role' => 'dba']);

    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'all-staff', 'members' => [['value' => (string) $user->id]]])
        ->assertCreated()->json('id');
    expect(scimRoleOf($user, $team))->toBe('dba');

    $world->call('PATCH', "/Groups/{$id}", ScimWorld::patch(['op' => 'remove', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]))->assertOk();
    $world->call('PATCH', "/Groups/{$id}", ScimWorld::patch(['op' => 'replace', 'path' => 'displayName', 'value' => 'everyone']))->assertOk();
    $world->call('PUT', "/Groups/{$id}", ['schemas' => [ScimWorld::GROUP], 'displayName' => 'everyone', 'members' => [['value' => (string) $user->id]]])->assertOk();
    $world->call('DELETE', "/Groups/{$id}")->assertNoContent();

    expect(scimRoleOf($user, $team))->toBe('dba')
        ->and(AuditLog::where('action', 'user.role_synced')->count())->toBe(0);
});

test('a mapped group changes only the teams it maps to', function () {
    [$world, $team, $user] = scimGroupSetup();
    config(['queryproxy.oidc.role_map' => 'platform-dba:payments:dba,ops:billing:dba']);
    $billing = Team::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    $billing->users()->attach($user, ['role' => 'dba']);

    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba', 'members' => [['value' => (string) $user->id]]])->json('id');
    expect(scimRoleOf($user, $team))->toBe('dba')->and(scimRoleOf($user, $billing))->toBe('dba');

    $world->call('DELETE', "/Groups/{$id}")->assertNoContent();

    // The ops role was not SCIM's to take away.
    expect(scimRoleOf($user, $team))->toBeNull()->and(scimRoleOf($user, $billing))->toBe('dba');
});

test('the role map may name a group by its externalId (Entra ID object id) or by its displayName (Okta, Keycloak)', function () {
    [$world, $team, $user] = scimGroupSetup();
    $guid = '0760b6cf-1f2e-4a0b-9c1d-6f0f0f0f0f0f';
    config(['queryproxy.oidc.role_map' => "{$guid}:payments:dba,platform-dev:payments:developer"]);
    $team->users()->attach($user, ['role' => 'auditor']);

    // Entra: the name means nothing to the map, the object id does.
    $entra = $world->call('POST', '/Groups', [
        'schemas' => [ScimWorld::GROUP], 'displayName' => 'Platform DBAs', 'externalId' => $guid,
        'members' => [['value' => (string) $user->id]],
    ])->assertCreated()->assertJsonPath('externalId', $guid)->json('id');
    expect(scimRoleOf($user, $team))->toBe('dba');

    $world->call('PATCH', "/Groups/{$entra}", ScimWorld::patch(['op' => 'Remove', 'path' => "members[value eq \"{$user->id}\"]"]))->assertOk();
    expect(scimRoleOf($user, $team))->toBeNull();

    // Okta: the display name is the key.
    $okta = $world->call('POST', '/Groups', [
        'schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dev', 'members' => [['value' => (string) $user->id]],
    ])->json('id');
    expect(scimRoleOf($user, $team))->toBe('developer');

    // A group that matches neither has no effect.
    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'Platform DBAs 2', 'externalId' => 'other-id', 'members' => [['value' => (string) $user->id]]])->assertCreated();
    expect(scimRoleOf($user, $team))->toBe('developer');

    // Giving a group the mapped id later grants the role to its members; taking it away removes it.
    $world->call('PATCH', "/Groups/{$okta}", ScimWorld::patch(['op' => 'replace', 'path' => 'externalId', 'value' => $guid]))->assertOk();
    expect(scimRoleOf($user, $team))->toBe('dba');
    $world->call('PATCH', "/Groups/{$okta}", ScimWorld::patch(['op' => 'remove', 'path' => 'externalId']))->assertOk();
    expect(scimRoleOf($user, $team))->toBe('developer');
});

test('an update keeps the members another update wrote after this one read them', function () {
    [$world, , $user] = scimGroupSetup();
    $late = ScimWorld::scimUser(['email' => 'late@example.test']);
    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba'])->json('id');

    // A concurrent PATCH commits its member between our read of the members and our write.
    $injected = false;
    DB::listen(function ($query) use (&$injected, $id, $late) {
        if ($injected || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'scim_group_user')) {
            return;
        }

        $injected = true;
        DB::table('scim_group_user')->insert(['scim_group_id' => $id, 'user_id' => $late->id, 'created_at' => now(), 'updated_at' => now()]);
    });

    $world->call('PATCH', "/Groups/{$id}", ScimWorld::patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]))->assertOk();

    expect($injected)->toBeTrue()
        ->and(DB::table('scim_group_user')->where('scim_group_id', $id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$user->id, $late->id])->sort()->values()->all());
});

test('the role follows the memberships as they are once the user row is locked, not as read before', function () {
    [$world, $team, $user] = scimGroupSetup();
    $id = $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'platform-dba'])->json('id');

    // A concurrent "remove" commits after our "add" committed and before we hold the user's lock.
    $attached = false;
    $removed = false;
    DB::listen(function ($query) use (&$attached, &$removed, $id, $user) {
        if (str_starts_with($query->sql, 'insert into "scim_group_user"')) {
            $attached = true;

            return;
        }

        if ($attached && ! $removed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "users"') && str_contains($query->sql, '"users"."id" = ?')) {
            $removed = true;
            DB::table('scim_group_user')->where('scim_group_id', $id)->where('user_id', $user->id)->delete();
        }
    });

    $world->call('PATCH', "/Groups/{$id}", ScimWorld::patch(['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]))->assertOk();

    // The user is in no group any more, so the stale "add" must not leave a role behind.
    expect($removed)->toBeTrue()
        ->and(DB::table('scim_group_user')->where('user_id', $user->id)->count())->toBe(0)
        ->and(scimRoleOf($user, $team))->toBeNull();
});

test('members are validated with one query, not one per member', function () {
    [$world] = scimGroupSetup();
    $members = collect(range(1, 12))->map(fn ($n) => ['value' => (string) ScimWorld::scimUser(['email' => "m{$n}@example.test"])->id])->all();

    $selects = 0;
    DB::listen(function ($query) use (&$selects) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "users"') && str_contains($query->sql, '"id" in')) {
            $selects++;
        }
    });

    $world->call('POST', '/Groups', ['schemas' => [ScimWorld::GROUP], 'displayName' => 'big', 'members' => $members])->assertCreated()->assertJsonCount(12, 'members');

    expect($selects)->toBe(1);
});
