<?php

use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Connections\Index as ConnectionsIndex;
use App\Livewire\Dashboard;
use App\Models\Connection;
use App\Models\ConnectionGrantRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Connections\GrantService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The screens of time-bound grants (ADR-025). The rules themselves are tested
 * in ConnectionGrantTest; these tests drive them through the components.
 *
 * @return array{0: Team, 1: User, 2: User, 3: Connection}
 */
function grantUiSetup(): array
{
    Notification::fake();

    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id, 'name' => 'orders-prod']);
    session(['current_team_id' => $team->id]);

    return [$team, $dba, $developer, $connection];
}

function submitAccessRequest(User $developer, Connection $connection, array $fields = []): mixed
{
    $fields += ['requestLevel' => 'read', 'requestDuration' => '240', 'requestReason' => 'incident 4711'];

    $component = Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->call('startAccessRequest', $connection->id);

    foreach ($fields as $name => $value) {
        $component->set($name, $value);
    }

    return $component->call('requestAccess');
}

test('a developer requests access to a connection and sees the request as pending', function () {
    [, , $developer, $connection] = grantUiSetup();

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('Request access')
        ->assertSee('orders-prod')
        ->call('startAccessRequest', $connection->id)
        ->assertSee('For how long')
        ->set('requestLevel', 'read')
        ->set('requestDuration', '240')
        ->set('requestReason', 'incident 4711')
        ->call('requestAccess')
        ->assertHasNoErrors()
        ->assertSet('requestingId', null)
        ->assertSee('Access request for orders-prod sent')
        ->assertSee('Request pending')
        ->assertSee('incident 4711');

    $request = ConnectionGrantRequest::sole();

    expect($request->user_id)->toBe($developer->id)
        ->and($request->connection_id)->toBe($connection->id)
        ->and($request->access_level)->toBe(GrantAccessLevel::Read)
        ->and($request->duration_minutes)->toBe(240)
        ->and($request->status)->toBe(GrantRequestStatus::Pending);
});

test('a custom period is converted to minutes and checked against the allowed range', function () {
    [, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection, [
        'requestDuration' => 'custom', 'requestCustomAmount' => '3', 'requestCustomUnit' => 'days',
    ])->assertHasNoErrors();

    expect(ConnectionGrantRequest::sole()->duration_minutes)->toBe(4320);

    ConnectionGrantRequest::query()->delete();

    foreach (['0', '-2', '1.5', 'abc', '', '31'] as $amount) {
        submitAccessRequest($developer, $connection, [
            'requestDuration' => 'custom', 'requestCustomAmount' => $amount, 'requestCustomUnit' => 'days',
        ])->assertHasErrors('requestDuration');
    }

    expect(ConnectionGrantRequest::count())->toBe(0);
});

test('the reason is required and the access level must be known', function () {
    [, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection, ['requestReason' => '   '])
        ->assertHasErrors(['requestReason' => 'required']);

    submitAccessRequest($developer, $connection, ['requestLevel' => 'admin'])
        ->assertHasErrors('requestLevel');

    expect(ConnectionGrantRequest::count())->toBe(0);
});

test('a second open request for the same connection shows the service message', function () {
    [, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();

    // The row now offers no button; a crafted call still gets the service's refusal.
    submitAccessRequest($developer, $connection)
        ->assertHasErrors('request')
        ->assertSee('You already have an open access request for this connection.');

    expect(ConnectionGrantRequest::count())->toBe(1);
});

test('a developer with a timed grant extends it at the same level and cannot ask for another', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addHour(), GrantAccessLevel::Read);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('Extend access')
        ->call('startAccessRequest', $connection->id)
        ->assertSet('requestLevel', 'read')
        ->assertSee('never shorten it or change its level')
        ->set('requestLevel', 'write')
        ->set('requestReason', 'need it longer')
        ->call('requestAccess')
        ->assertHasErrors('request')
        ->assertSee('A DBA changes the existing grant directly.');

    expect(ConnectionGrantRequest::count())->toBe(0);
});

test('a permanent grant offers nothing to request', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, null, GrantAccessLevel::Write);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertDontSee('Request access to')
        ->assertDontSee('Extend access')
        ->call('startAccessRequest', $connection->id)
        ->assertSet('requestingId', null)
        ->assertSee('You already have permanent write access to this connection.');
});

test('a DBA and an auditor do not get the request panel', function () {
    [$team, $dba] = grantUiSetup();
    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);

    foreach ([$dba, $auditor] as $user) {
        Livewire::actingAs($user)->test(Dashboard::class)->assertViewHas('access', null)->assertDontSee('Request access');
    }

    Livewire::actingAs($auditor)->test(Dashboard::class)->call('startAccessRequest', 1)->assertForbidden();
});

test('the same-team DBA approves the request and the developer then sees the connection with its remaining time', function () {
    [, $dba, $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    expect($connection->isGrantedTo($developer))->toBeFalse();

    Livewire::actingAs($dba)
        ->test(ApprovalsIndex::class)
        ->call('showTab', 'access')
        ->assertSee('Access requests · 1')
        ->assertSee('orders-prod')
        ->assertSee('incident 4711')
        ->assertSee($developer->name)
        ->call('approveGrant', $grantRequest->id)
        ->assertHasNoErrors()
        ->assertSee('approved')
        ->assertSee('No access requests waiting.');

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Approved)
        ->and($connection->isGrantedTo($developer))->toBeTrue();

    $component = Livewire::actingAs($developer)->test(Dashboard::class);

    $component->assertSeeInOrder(['Your connections', 'orders-prod', 'Read'])
        ->assertSee('3h 59m left', false)
        ->assertSee('Approved');

    // The developer gets nothing to ask for on a connection with a 4h grant? They may extend it.
    $component->assertSee('Extend access');
});

test('a DBA rejects a request with a reason and the developer reads it', function () {
    [, $dba, $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    Livewire::actingAs($dba)
        ->test(ApprovalsIndex::class)
        ->call('showTab', 'access')
        ->call('startRejectGrant', $grantRequest->id)
        ->call('rejectGrant')
        ->assertHasErrors(['grantRejectionNote' => 'required'])
        ->set('grantRejectionNote', 'use the replica')
        ->call('rejectGrant')
        ->assertHasNoErrors()
        ->assertSet('rejectingGrantId', null)
        ->assertSee('rejected');

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Rejected)
        ->and($connection->isGrantedTo($developer))->toBeFalse();

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('Rejected')
        ->assertSee('use the replica')
        ->assertDontSee('Request pending')
        // After a rejection the developer may ask again.
        ->assertSee('Request access');
});

test('the approval screen refuses a request that asks for another level than the grant in force', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    $service = app(GrantService::class);

    submitAccessRequest($developer, $connection, ['requestLevel' => 'read'])->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    // The DBA gives write access directly while the read request is open.
    $service->grant($connection, $developer, $dba, now()->addDay(), GrantAccessLevel::Write);

    $component = Livewire::actingAs($dba)
        ->test(ApprovalsIndex::class)
        ->call('showTab', 'access')
        ->assertSee('asks for another level, so it cannot be approved')
        ->assertDontSee('Approve access')
        ->call('approveGrant', $grantRequest->id)
        ->assertHasErrors('grant')
        ->assertSee('would replace it. A DBA changes the existing grant directly.');

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Pending)
        ->and($connection->grantFor($developer)->access_level)->toBe(GrantAccessLevel::Write);

    $component->call('startRejectGrant', $grantRequest->id)
        ->set('grantRejectionNote', 'already has write')
        ->call('rejectGrant')
        ->assertHasNoErrors();
});

test('a request that was decided meanwhile shows the service message', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    $service = app(GrantService::class);

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    $component = Livewire::actingAs($dba)->test(ApprovalsIndex::class)->call('showTab', 'access');

    $other = User::factory()->create();
    $grantRequest->team->users()->attach($other, ['role' => 'dba']);
    $service->reject($grantRequest, $other, 'no');

    $component->call('approveGrant', $grantRequest->id)
        ->assertHasErrors('grant')
        ->assertSee('This request has already been decided.');
});

test('a developer cannot open the approvals page or decide a request', function () {
    [$team, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    $this->actingAs($developer)->withSession(['current_team_id' => $team->id])
        ->get(route('approvals.index', ['tab' => 'access']))
        ->assertForbidden();

    // Component actions arrive on the Livewire endpoint; the service decides there.
    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'developer']);
    session(['current_team_id' => $team->id]);

    foreach ([$developer, $other] as $user) {
        Livewire::actingAs($user)->test(ApprovalsIndex::class)->call('approveGrant', $grantRequest->id)->assertForbidden();

        Livewire::actingAs($user)->test(ApprovalsIndex::class)
            ->set('rejectingGrantId', $grantRequest->id)
            ->set('grantRejectionNote', 'no')
            ->call('rejectGrant')
            ->assertForbidden();
    }

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Pending)
        ->and($connection->isGrantedTo($developer))->toBeFalse();
});

test('a user cannot decide their own request even after becoming a DBA', function () {
    [$team, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    $team->users()->updateExistingPivot($developer->id, ['role' => 'dba']);

    Livewire::actingAs($developer->fresh())
        ->test(ApprovalsIndex::class)
        ->call('showTab', 'access')
        ->assertSee('Your own request')
        ->assertDontSee('Approve access')
        ->call('approveGrant', $grantRequest->id)
        ->assertForbidden();

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Pending);
});

test('a DBA of another team neither sees nor decides the request', function () {
    [, , $developer, $connection] = grantUiSetup();

    submitAccessRequest($developer, $connection)->assertHasNoErrors();
    $grantRequest = ConnectionGrantRequest::sole();

    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $otherTeam->users()->attach($outsider, ['role' => 'dba']);
    session(['current_team_id' => $otherTeam->id]);

    Livewire::actingAs($outsider)
        ->test(ApprovalsIndex::class)
        ->call('showTab', 'access')
        ->assertSee('Access requests · 0')
        ->assertDontSee('orders-prod')
        ->call('approveGrant', $grantRequest->id)
        ->assertNotFound();

    expect($grantRequest->fresh()->status)->toBe(GrantRequestStatus::Pending);
});

test('the query requests tab is still the default', function () {
    [, $dba] = grantUiSetup();

    Livewire::actingAs($dba)
        ->test(ApprovalsIndex::class)
        ->assertSet('tab', 'queries')
        ->assertSee('Nothing waiting for approval.')
        ->assertDontSee('No access requests waiting.');
});

test('a DBA gives a four hour read grant and the list shows level and remaining time', function () {
    [, $dba, $developer, $connection] = grantUiSetup();

    Livewire::actingAs($dba)
        ->test(ConnectionsIndex::class)
        ->call('toggleGrants', $connection->id)
        ->assertSee('Grant access')
        ->assertSee('no access')
        ->set('grantLevel', 'read')
        ->set('grantDuration', '240')
        ->set('grantReason', 'on call')
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasNoErrors()
        ->assertSee('Read')
        ->assertSee('3h 59m left', false)
        ->assertSee('Replace grant');

    $grant = $connection->grantFor($developer);

    expect($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and($grant->isPermanent())->toBeFalse()
        ->and($grant->expires_at->between(now()->addMinutes(239), now()->addMinutes(241)))->toBeTrue()
        ->and($grant->reason)->toBe('on call')
        ->and($grant->granted_by)->toBe($dba->id);
});

test('the default grant is permanent, and the DBA can change an existing grant directly', function () {
    [, $dba, $developer, $connection] = grantUiSetup();

    $component = Livewire::actingAs($dba)
        ->test(ConnectionsIndex::class)
        ->call('toggleGrants', $connection->id)
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasNoErrors()
        ->assertSee('permanent');

    expect($connection->grantFor($developer))->access_level->toBe(GrantAccessLevel::Write)
        ->and($connection->grantFor($developer)->isPermanent())->toBeTrue();

    $component->set('grantLevel', 'read')
        ->set('grantDuration', 'custom')
        ->set('grantCustomAmount', '2')
        ->set('grantCustomUnit', 'days')
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasNoErrors();

    $grant = $connection->grantFor($developer);

    expect($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and($grant->expires_at->between(now()->addMinutes(2879), now()->addMinutes(2881)))->toBeTrue();

    $component->call('revokeGrant', $connection->id, $developer->id)->assertHasNoErrors();

    expect($connection->isGrantedTo($developer))->toBeFalse();
});

test('replacing a grant asks first, naming the old and the new grant, and cancelling keeps it', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addHours(4), GrantAccessLevel::Read);

    // The panel still holds its defaults: permanent write access.
    $component = Livewire::actingAs($dba)
        ->test(ConnectionsIndex::class)
        ->call('toggleGrants', $connection->id)
        ->assertDontSee('Confirm replace')
        ->call('startReplace', $connection->id, $developer->id)
        ->assertSee('Confirm replace')
        ->assertSee('Replace read access until')
        ->assertSee('with write access, permanent')
        ->set('grantLevel', 'read')
        ->set('grantDuration', '1440')
        ->assertSee('with read access for 1 day');

    expect($connection->grantFor($developer)->access_level)->toBe(GrantAccessLevel::Read)
        ->and($connection->grantFor($developer)->isPermanent())->toBeFalse();

    $component->call('cancelReplace')->assertDontSee('Confirm replace');

    expect($connection->grantFor($developer)->expires_at->between(now()->addHours(4)->subMinute(), now()->addHours(4)->addMinute()))->toBeTrue();

    $component->call('startReplace', $connection->id, $developer->id)
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasNoErrors()
        ->assertSet('replacingKey', null)
        ->assertDontSee('Confirm replace');

    expect($connection->grantFor($developer)->expires_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()))->toBeTrue();
});

test('the grants panel refuses an out of range period and shows the service message', function () {
    [, $dba, $developer, $connection] = grantUiSetup();

    Livewire::actingAs($dba)
        ->test(ConnectionsIndex::class)
        ->call('toggleGrants', $connection->id)
        ->set('grantDuration', 'custom')
        ->set('grantCustomAmount', '31')
        ->set('grantCustomUnit', 'days')
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasErrors('grantDuration')
        ->assertSee('at most 30 days')
        ->set('grantCustomAmount', '1')
        ->set('grantCustomUnit', 'hours')
        ->set('grantReason', str_repeat('x', 1001))
        ->call('grantAccess', $connection->id, $developer->id)
        ->assertHasErrors('grant')
        ->assertSee('at most 1000 characters');

    expect($connection->isGrantedTo($developer))->toBeFalse();
});

test('a developer cannot give or revoke grants through the connections component', function () {
    [$team, , $developer, $connection] = grantUiSetup();
    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'developer']);
    session(['current_team_id' => $team->id]);

    Livewire::actingAs($developer)
        ->test(ConnectionsIndex::class)
        ->call('grantAccess', $connection->id, $other->id)
        ->assertForbidden();

    expect($connection->isGrantedTo($other))->toBeFalse();

    $connection->grantedUsers()->attach($other);

    Livewire::actingAs($developer)
        ->test(ConnectionsIndex::class)
        ->call('revokeGrant', $connection->id, $other->id)
        ->assertForbidden();

    expect($connection->isGrantedTo($other))->toBeTrue();
});

test('after the end time the connection leaves the developer view and can be requested again', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addHour(), GrantAccessLevel::Read);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('59m')->assertSee(' left', false)
        ->assertDontSee('59s');

    $this->travel(61)->minutes();

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertDontSee('m left', false)
        ->assertDontSee('Extend access')
        ->assertSee('Request access');
});

test('the last minute of a grant reads "<1m left", never "0m"', function () {
    [, $dba, $developer, $connection] = grantUiSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addMinutes(5), GrantAccessLevel::Read);

    $this->travel(4)->minutes();
    $this->travel(30)->seconds();

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('<1m left')
        ->assertDontSee('0m left');

    Livewire::actingAs($dba)
        ->test(ConnectionsIndex::class)
        ->call('toggleGrants', $connection->id)
        ->assertSee('<1m left')
        ->assertDontSee('0m left');

    $this->travel(2)->minutes();

    Livewire::actingAs($developer)->test(Dashboard::class)->assertDontSee('<1m left');
});
