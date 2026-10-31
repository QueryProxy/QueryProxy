<?php

use App\Enums\AuditEvent;
use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use App\Livewire\Connections\Index as ConnectionsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Studio\QueryStudio;
use App\Models\AuditLog;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\ConnectionGrantRequest;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\GrantDecided;
use App\Notifications\GrantRequested;
use App\Services\Connections\GrantException;
use App\Services\Connections\GrantService;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * A team with a DBA, a developer (no grant yet) and one connection.
 *
 * @return array{0: Team, 1: User, 2: User, 3: Connection}
 */
function grantSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    session(['current_team_id' => $team->id]);

    return [$team, $dba, $developer, $connection];
}

function grantAudit(string $action): Collection
{
    return AuditLog::where('action', $action)->get();
}

test('a grant without an end time is permanent write access, as before', function () {
    [, , $developer, $connection] = grantSetup();

    $connection->grantedUsers()->attach($developer);

    $grant = $connection->grantFor($developer);

    expect($connection->isGrantedTo($developer))->toBeTrue()
        ->and($grant->isPermanent())->toBeTrue()
        ->and($grant->access_level)->toBe(GrantAccessLevel::Write)
        ->and($developer->can('use', $connection))->toBeTrue();
});

test('a one hour grant ends on time and the job records grant.expired', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addHour(), GrantAccessLevel::Read, 'incident 4711');

    expect($developer->can('use', $connection))->toBeTrue();

    $this->travel(61)->minutes();

    // Access has ended although the row is still there.
    expect($connection->isGrantedTo($developer))->toBeFalse()
        ->and($developer->can('use', $connection))->toBeFalse()
        ->and(DB::table('connection_user')->count())->toBe(1)
        ->and(fn () => app(RequestSubmissionService::class)->submit($developer, $connection, 'SELECT 1', null))
        ->toThrow(AuthorizationException::class);

    $this->artisan('connections:expire-grants')->expectsOutputToContain('Removed 1 expired grant(s).')->assertSuccessful();

    $log = grantAudit('grant.expired');

    expect(DB::table('connection_user')->count())->toBe(0)
        ->and($log)->toHaveCount(1)
        ->and($log->first()->connection_id)->toBe($connection->id)
        ->and($log->first()->user_id)->toBeNull()
        ->and($log->first()->metadata)->toMatchArray([
            'actor' => 'system',
            'developer' => $developer->email,
            'access_level' => 'read',
        ]);

    // A second run finds nothing and records nothing more.
    $this->artisan('connections:expire-grants')->expectsOutputToContain('Removed 0 expired grant(s).');
    expect(grantAudit('grant.expired'))->toHaveCount(1);
});

test('submitting through an expired grant is forbidden in the studio', function () {
    [, $dba, $developer, $connection] = grantSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addHour());

    $this->travel(61)->minutes();

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1')
        ->call('submit')
        ->assertForbidden();

    expect(QueryRequest::count())->toBe(0);
});

test('a permanent grant is not touched by the expiry job', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $other = User::factory()->create();
    $connection->team->users()->attach($other, ['role' => 'developer']);

    $service = app(GrantService::class);
    $service->grant($connection, $developer, $dba);
    $service->grant($connection, $other, $dba, now()->addMinutes(10));

    $this->travel(2)->days();
    $this->artisan('connections:expire-grants')->assertSuccessful();

    expect($connection->isGrantedTo($developer))->toBeTrue()
        ->and(DB::table('connection_user')->count())->toBe(1)
        ->and(grantAudit('grant.expired'))->toHaveCount(1);
});

test('a grant extended after the scan is not removed', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addMinutes(10));
    $this->travel(11)->minutes();

    // The DBA extends it before the job runs.
    $service->grant($connection, $developer, $dba, now()->addHour());
    $this->artisan('connections:expire-grants')->assertSuccessful();

    expect($connection->isGrantedTo($developer))->toBeTrue()
        ->and(grantAudit('grant.expired'))->toHaveCount(0);
});

test('the dashboard stops listing a connection once the grant has ended', function () {
    [, $dba, $developer, $connection] = grantSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, now()->addHour());

    Livewire::actingAs($developer)->test(Dashboard::class)
        ->assertViewHas('connections', fn ($connections) => $connections->count() === 1);

    $this->travel(61)->minutes();

    Livewire::actingAs($developer)->test(Dashboard::class)
        ->assertViewHas('connections', fn ($connections) => $connections->count() === 0);
});

test('a read grant files SELECT but not writes or DDL', function () {
    [, $dba, $developer, $connection] = grantSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, null, GrantAccessLevel::Read);
    $submissions = app(RequestSubmissionService::class);

    $request = $submissions->submit($developer, $connection, 'SELECT * FROM customers', null);
    expect($request->type->value)->toBe('read');

    foreach (['UPDATE customers SET name = \'x\' WHERE id = 1', 'DELETE FROM customers WHERE id = 1'] as $sql) {
        expect(fn () => $submissions->submit($developer, $connection, $sql, null))
            ->toThrow(AuthorizationException::class, 'read-only');
    }

    expect(QueryRequest::count())->toBe(1);
});

test('a read grant is refused a write in the studio with 403', function () {
    [, $dba, $developer, $connection] = grantSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, null, GrantAccessLevel::Read);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'UPDATE customers SET name = \'x\' WHERE id = 1')
        ->call('submit')
        ->assertForbidden();

    expect(QueryRequest::count())->toBe(0);
});

test('a write grant and a DBA can still file writes', function () {
    [, $dba, $developer, $connection] = grantSetup();
    app(GrantService::class)->grant($connection, $developer, $dba, null, GrantAccessLevel::Write);
    $submissions = app(RequestSubmissionService::class);
    $sql = 'UPDATE customers SET name = \'x\' WHERE id = 1';

    expect($submissions->submit($developer, $connection, $sql, null)->type->value)->toBe('write')
        ->and($submissions->submit($dba, $connection, $sql, null)->type->value)->toBe('write');
});

test('a DBA gives and revokes access through the service with audit records', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addDay(), GrantAccessLevel::Read, '  on call  ');

    $grant = $connection->grantFor($developer);
    expect($grant->granted_by)->toBe($dba->id)
        ->and($grant->reason)->toBe('on call')
        ->and($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and($grant->expires_at->isFuture())->toBeTrue()
        ->and(grantAudit('connection.grant_added'))->toHaveCount(1);

    // Granting again replaces the row instead of violating the unique key.
    $service->grant($connection, $developer, $dba);
    expect($connection->grantFor($developer)->isPermanent())->toBeTrue()
        ->and(DB::table('connection_user')->count())->toBe(1);

    expect($service->revoke($connection, $developer, $dba))->toBeTrue()
        ->and($connection->isGrantedTo($developer))->toBeFalse()
        ->and(grantAudit('connection.grant_revoked'))->toHaveCount(1)
        ->and($service->revoke($connection, $developer, $dba))->toBeFalse()
        ->and(grantAudit('connection.grant_revoked'))->toHaveCount(1);
});

test('grant and revoke are refused for non-DBAs and for non-developers', function () {
    [$team, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);

    expect(fn () => $service->grant($connection, $developer, $developer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->revoke($connection, $developer, $developer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->grant($connection, $auditor, $dba))->toThrow(GrantException::class)
        ->and(fn () => $service->grant($connection, $developer, $dba, now()->subMinute()))->toThrow(GrantException::class);
});

test('re-granting over an expired row that the job has not removed yet works', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addMinutes(5));
    $this->travel(10)->minutes();
    $service->grant($connection, $developer, $dba, now()->addHour());

    expect($connection->isGrantedTo($developer))->toBeTrue()
        ->and(DB::table('connection_user')->count())->toBe(1);
});

test('the connections page grants with a period and revokes through the service', function () {
    [, $dba, $developer, $connection] = grantSetup();

    $component = Livewire::actingAs($dba)->test(ConnectionsIndex::class);

    $component->set('grantLevel', 'read')
        ->set('grantDuration', 'custom')
        ->set('grantCustomAmount', '90')
        ->set('grantCustomUnit', 'minutes')
        ->call('grantAccess', $connection->id, $developer->id);

    $grant = $connection->grantFor($developer);
    expect($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and($grant->expires_at->between(now()->addMinutes(89), now()->addMinutes(91)))->toBeTrue();

    $component->call('revokeGrant', $connection->id, $developer->id);
    expect($connection->isGrantedTo($developer))->toBeFalse()
        ->and(grantAudit('connection.grant_revoked'))->toHaveCount(1);

    $component->set('grantCustomAmount', '0')->call('grantAccess', $connection->id, $developer->id)->assertHasErrors('grantDuration');
    $component->set('grantDuration', 'permanent')->set('grantLevel', 'admin')->call('grantAccess', $connection->id, $developer->id)->assertHasErrors('grantLevel');
    expect($connection->isGrantedTo($developer))->toBeFalse();

    $component->set('grantLevel', 'write')->call('grantAccess', $connection->id, $developer->id);
    expect($connection->grantFor($developer)->isPermanent())->toBeTrue();
});

test('a developer requests access and the DBAs are told', function () {
    Notification::fake();
    Http::fake();
    [$team, $dba, $developer, $connection] = grantSetup();
    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'slack-secret',
        'enabled' => true,
    ]);

    $request = app(GrantService::class)->request($developer, $connection, GrantAccessLevel::Read, 240, '  <!channel> prod incident  ');

    expect($request->status)->toBe(GrantRequestStatus::Pending)
        ->and($request->duration_minutes)->toBe(240)
        ->and($request->reason)->toBe('<!channel> prod incident')
        ->and($connection->isGrantedTo($developer))->toBeFalse()
        ->and(grantAudit('grant.requested'))->toHaveCount(1)
        ->and(grantAudit('grant.requested')->first()->user_id)->toBe($developer->id);

    Notification::assertSentTo($dba, GrantRequested::class, fn ($n) => $n->grantRequest->is($request));
    Notification::assertNotSentTo($developer, GrantRequested::class);

    // Chat gets the fact of the request, never the free-text reason.
    Http::assertSent(fn ($r) => str_contains($r['text'], 'asked for read access')
        && str_contains($r['text'], '4 hours')
        && ! str_contains($r['text'], 'channel'));
});

test('approving starts the grant now, audits it and tells the requester', function () {
    Notification::fake();
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Read, 240, 'incident');

    $this->travel(30)->minutes();
    $decided = $service->approve($request, $dba);

    $grant = $connection->grantFor($developer);

    expect($decided->status)->toBe(GrantRequestStatus::Approved)
        ->and($decided->decided_by)->toBe($dba->id)
        ->and($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and($grant->granted_by)->toBe($dba->id)
        ->and($grant->reason)->toBe('incident')
        ->and($grant->expires_at->between(now()->addMinutes(239), now()->addMinutes(241)))->toBeTrue()
        ->and(grantAudit('grant.approved'))->toHaveCount(1)
        ->and(grantAudit('grant.approved')->first()->user_id)->toBe($dba->id);

    Notification::assertSentTo($developer, GrantDecided::class);

    $this->travel(241)->minutes();
    expect($connection->isGrantedTo($developer))->toBeFalse();
});

test('rejecting records the reason and grants nothing', function () {
    Notification::fake();
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'cleanup');

    $decided = $service->reject($request, $dba, 'use the staging copy');

    expect($decided->status)->toBe(GrantRequestStatus::Rejected)
        ->and($decided->decision_note)->toBe('use the staging copy')
        ->and($connection->isGrantedTo($developer))->toBeFalse()
        ->and(grantAudit('grant.rejected'))->toHaveCount(1)
        ->and(grantAudit('grant.rejected')->first()->metadata['reason'])->toBe('use the staging copy');

    Notification::assertSentTo($developer, GrantDecided::class, fn ($n, $channels) => str_contains($n->toArray($developer)['message'], 'rejected'));
});

test('a DBA cannot approve or reject their own grant request', function () {
    [$team, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'need it');

    // The requester is promoted to DBA while the request is still open.
    $team->users()->updateExistingPivot($developer->id, ['role' => 'dba']);
    $promoted = User::find($developer->id);

    expect(fn () => $service->approve($request, $promoted))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->reject($request, $promoted))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(GrantRequestStatus::Pending)
        ->and(DB::table('connection_user')->count())->toBe(0)
        ->and(grantAudit('grant.approved'))->toHaveCount(0);

    // Back to developer, another DBA can decide.
    $team->users()->updateExistingPivot($developer->id, ['role' => 'developer']);
    $service->approve($request->fresh(), $dba);
    expect($request->fresh()->status)->toBe(GrantRequestStatus::Approved);
});

test('only a DBA of the connection team decides', function () {
    [, , $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'look');

    $peer = User::factory()->create();
    $connection->team->users()->attach($peer, ['role' => 'developer']);
    $foreignTeam = Team::factory()->create();
    $foreignDba = User::factory()->create();
    $foreignTeam->users()->attach($foreignDba, ['role' => 'dba']);
    $admin = User::factory()->create(['is_admin' => true]);

    expect(fn () => $service->approve($request, $peer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->approve($request, $foreignDba))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->isPending())->toBeTrue();

    $service->approve($request, $admin);
    expect($connection->isGrantedTo($developer))->toBeTrue();
});

test('a request is decided once', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'look');

    $service->approve($request, $dba);

    expect(fn () => $service->approve($request, $dba))->toThrow(GrantException::class, 'already been decided')
        ->and(fn () => $service->reject($request, $dba))->toThrow(GrantException::class, 'already been decided')
        ->and(grantAudit('grant.approved'))->toHaveCount(1);
});

test('requests are validated', function () {
    [$team, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    expect(fn () => $service->request($developer, $connection, GrantAccessLevel::Read, 0, 'x'))->toThrow(GrantException::class)
        ->and(fn () => $service->request($developer, $connection, GrantAccessLevel::Read, GrantService::MAX_DURATION_MINUTES + 1, 'x'))->toThrow(GrantException::class)
        ->and(fn () => $service->request($developer, $connection, GrantAccessLevel::Read, 60, '   '))->toThrow(GrantException::class)
        ->and(fn () => $service->request($dba, $connection, GrantAccessLevel::Read, 60, 'x'))->toThrow(AuthorizationException::class);

    $outsider = User::factory()->create();
    expect(fn () => $service->request($outsider, $connection, GrantAccessLevel::Read, 60, 'x'))->toThrow(AuthorizationException::class);

    $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'first');

    expect(fn () => $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'second'))
        ->toThrow(GrantException::class, 'open access request')
        ->and(ConnectionGrantRequest::count())->toBe(1);
});

test('a permanent write grant makes a request pointless', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $service->grant($connection, $developer, $dba);

    expect(fn () => $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'x'))
        ->toThrow(GrantException::class, 'permanent write access');
});

test('a request for another level than the grant in force is refused when filed and when approved', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    // Permanent read + a write request: refused, the standing grant is untouched.
    $service->grant($connection, $developer, $dba, null, GrantAccessLevel::Read, 'standing');

    expect(fn () => $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'one write'))
        ->toThrow(GrantException::class, 'would replace it');

    $grant = $connection->grantFor($developer);
    expect($grant->isPermanent())->toBeTrue()
        ->and($grant->access_level)->toBe(GrantAccessLevel::Read)
        ->and(ConnectionGrantRequest::count())->toBe(0);

    // A timed grant of another level is refused the same way.
    $service->revoke($connection, $developer, $dba);
    $service->grant($connection, $developer, $dba, now()->addDays(2), GrantAccessLevel::Write);

    expect(fn () => $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'again'))
        ->toThrow(GrantException::class, 'would replace it');

    // Filed while no grant existed, then the DBA gave another level: the approval is refused.
    $service->revoke($connection, $developer, $dba);
    $pending = $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'write please');
    $service->grant($connection, $developer, $dba, null, GrantAccessLevel::Read);

    expect(fn () => $service->approve($pending, $dba))->toThrow(GrantException::class, 'would replace it')
        ->and($pending->fresh()->isPending())->toBeTrue()
        ->and($connection->grantFor($developer)->access_level)->toBe(GrantAccessLevel::Read)
        ->and($connection->grantFor($developer)->isPermanent())->toBeTrue()
        ->and(grantAudit('grant.approved'))->toHaveCount(0);

    $service->reject($pending, $dba, 'you have read access');
    expect($pending->fresh()->status)->toBe(GrantRequestStatus::Rejected);
});

test('a permanent grant of the same level leaves nothing to request or approve', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $pending = $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'look');
    $service->grant($connection, $developer, $dba, null, GrantAccessLevel::Read);

    expect(fn () => $service->approve($pending, $dba))->toThrow(GrantException::class, 'permanent read access')
        ->and($pending->fresh()->isPending())->toBeTrue()
        ->and($connection->grantFor($developer)->isPermanent())->toBeTrue();
});

test('a same-level approval only extends a timed grant, never shortens or makes it permanent', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    // Longer grant first: a shorter approval leaves the end time alone.
    $service->grant($connection, $developer, $dba, now()->addDays(2), GrantAccessLevel::Read, 'standing');
    $endsAt = $connection->grantFor($developer)->expires_at;
    $short = $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'again');
    $service->approve($short, $dba);

    $grant = $connection->grantFor($developer);
    expect($grant->expires_at->equalTo($endsAt))->toBeTrue()
        ->and($grant->reason)->toBe('standing')
        ->and(grantAudit('grant.approved')->first()->metadata['extended'])->toBeFalse();

    // Shorter grant first: the approval extends it, still timed.
    $service->revoke($connection, $developer, $dba);
    $service->grant($connection, $developer, $dba, now()->addMinutes(10), GrantAccessLevel::Read);
    $long = $service->request($developer, $connection, GrantAccessLevel::Read, 180, 'more time');
    $service->approve($long, $dba);

    $grant = $connection->grantFor($developer);
    expect($grant->isPermanent())->toBeFalse()
        ->and($grant->expires_at->between(now()->addMinutes(179), now()->addMinutes(181)))->toBeTrue()
        ->and($grant->reason)->toBe('more time');

    $this->travel(181)->minutes();
    expect($connection->isGrantedTo($developer))->toBeFalse();
});

test('an approved timed write grant ends on time and the user is back to no access', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $sql = 'UPDATE customers SET name = \'x\' WHERE id = 1';

    $request = $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'one fix');
    $service->approve($request, $dba);

    expect(app(RequestSubmissionService::class)->submit($developer, $connection, $sql, null)->type->value)->toBe('write');

    $this->travel(61)->minutes();

    expect(fn () => app(RequestSubmissionService::class)->submit($developer, $connection, $sql, null))
        ->toThrow(AuthorizationException::class);

    $this->artisan('connections:expire-grants')->assertSuccessful();

    expect(grantAudit('grant.expired'))->toHaveCount(1)
        ->and(DB::table('connection_user')->count())->toBe(0);
});

test('the user relation lists only grants in force', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $other = Connection::factory()->create(['team_id' => $connection->team_id]);
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addHour(), GrantAccessLevel::Read);
    $service->grant($other, $developer, $dba);

    expect($developer->grantedConnections()->count())->toBe(2)
        ->and($developer->grantedConnections()->whereKey($connection->id)->first()->pivot->access_level)->toBe(GrantAccessLevel::Read);

    $this->travel(61)->minutes();

    expect($developer->grantedConnections()->pluck('connections.id')->all())->toBe([$other->id]);
});

test('a requester who lost the developer role cannot be approved', function () {
    [$team, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $request = $service->request($developer, $connection, GrantAccessLevel::Read, 60, 'look');

    $team->users()->detach($developer->id);

    expect(fn () => $service->approve(ConnectionGrantRequest::find($request->id), $dba))->toThrow(GrantException::class)
        ->and($request->fresh()->isPending())->toBeTrue()
        ->and(DB::table('connection_user')->count())->toBe(0);
});

test('the expiry command is scheduled every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'connections:expire-grants'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

test('the new audit events are catalogued and the chain stays valid', function () {
    foreach (['grant.requested', 'grant.approved', 'grant.rejected', 'grant.expired', 'connection.grant_revoked'] as $name) {
        expect(AuditEvent::tryFrom($name))->not->toBeNull();
    }

    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);
    $service->grant($connection, $developer, $dba, now()->addMinute());
    $this->travel(2)->minutes();
    $service->expireDue();

    $this->artisan('audit:verify')->assertSuccessful();
});

test('chat messages escape Slack control characters in names', function () {
    Http::fake();
    [$team, $dba, $developer, $connection] = grantSetup();
    $developer->forceFill(['name' => '<!channel> & co'])->save();
    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'slack-secret',
        'enabled' => true,
    ]);

    app(GrantService::class)->request(User::find($developer->id), $connection, GrantAccessLevel::Read, 60, 'look');

    Http::assertSent(fn ($r) => str_contains($r['text'], '&lt;!channel&gt; &amp; co') && ! str_contains($r['text'], '<!channel>'));
});

test('the approval messages state the grant that is in force, not the period asked for', function () {
    Notification::fake();
    Http::fake();
    [$team, $dba, $developer, $connection] = grantSetup();
    ChatIntegration::create([
        'team_id' => $team->id,
        'provider' => 'slack',
        'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXX',
        'signing_secret' => 'slack-secret',
        'enabled' => true,
    ]);
    $service = app(GrantService::class);

    // A longer grant is kept; the message must say so.
    $service->grant($connection, $developer, $dba, now()->addDays(2), GrantAccessLevel::Write);
    $request = $service->request($developer, $connection, GrantAccessLevel::Write, 60, 'again');
    $service->approve($request, $dba);

    $expected = $connection->grantFor($developer)->describe();

    expect($expected)->toContain('write access until');

    Notification::assertSentTo($developer, GrantDecided::class, function ($n) use ($expected, $developer) {
        $message = $n->toArray($developer)['message'];

        return str_contains($message, $expected) && ! str_contains($message, 'for 1 hour');
    });
    Http::assertSent(fn ($r) => str_contains($r['text'], 'now in force: '.$expected));
});

test('revoking by hand keeps the published connection.grant_revoked event', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addHour(), GrantAccessLevel::Read);
    $service->revoke($connection, $developer, $dba);

    $log = grantAudit('connection.grant_revoked')->first();

    expect($log->user_id)->toBe($dba->id)
        ->and($log->metadata)->toMatchArray(['developer' => $developer->email, 'access_level' => 'read', 'was_expired' => false])
        ->and(AuditLog::where('action', 'grant.revoked')->count())->toBe(0);
});

test('an approval does not overwrite a grant a DBA changed at the same moment', function () {
    [, $dba, $developer, $connection] = grantSetup();
    $service = app(GrantService::class);

    $service->grant($connection, $developer, $dba, now()->addMinutes(10), GrantAccessLevel::Read, 'standing');
    $pending = $service->request($developer, $connection, GrantAccessLevel::Read, 180, 'more time');
    $narrowedTo = now()->addMinutes(2)->startOfSecond();

    // Another DBA shortens the grant right before the approval writes. (The
    // test runs inside one transaction, so the failed approval's rollback also
    // takes this change back; what matters is that the approval did not write.)
    $raced = false;
    DB::beforeExecuting(function (string $query) use (&$raced, $connection, $developer, $narrowedTo) {
        if (! $raced && str_starts_with($query, 'update "connection_user"')) {
            $raced = true;
            DB::table('connection_user')
                ->where('connection_id', $connection->id)
                ->where('user_id', $developer->id)
                ->update(['expires_at' => $narrowedTo]);
        }
    });

    expect(fn () => $service->approve($pending, $dba))->toThrow(GrantException::class, 'changed while');

    $grant = $connection->grantFor($developer);

    expect($raced)->toBeTrue()
        ->and($grant->expires_at->isBefore(now()->addMinutes(11)))->toBeTrue()
        ->and($grant->reason)->toBe('standing')
        ->and($pending->fresh()->isPending())->toBeTrue()
        ->and(grantAudit('grant.approved'))->toHaveCount(0);
});
