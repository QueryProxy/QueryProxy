<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Approvals\Index;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestDecided;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function approvalSetup(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $developer->id,
    ]);

    session(['current_team_id' => $team->id]);

    return [$team, $dba, $developer, $request];
}

test('a dba can approve a pending request which queues execution', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, $developer, $request] = approvalSetup();

    Livewire::actingAs($dba)->test(Index::class)->call('approve', $request->id);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Queued)
        ->and($request->reviewed_by)->toBe($dba->id)
        ->and($request->review_channel)->toBe('web');

    Queue::assertPushedOn('queries', ExecuteQueryRequest::class);
    Notification::assertSentTo($developer, QueryRequestDecided::class);
});

test('rejection requires a reason and notifies the requester', function () {
    Notification::fake();

    [, $dba, $developer, $request] = approvalSetup();

    $component = Livewire::actingAs($dba)->test(Index::class)
        ->call('startReject', $request->id)
        ->call('reject')
        ->assertHasErrors('rejectionReason');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);

    $component->set('rejectionReason', 'Touches production billing tables.')
        ->call('reject')
        ->assertHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Rejected)
        ->and($request->rejection_reason)->toBe('Touches production billing tables.');

    Notification::assertSentTo($developer, QueryRequestDecided::class);
});

test('dbas cannot approve their own requests', function () {
    [$team, $dba] = approvalSetup();

    $ownRequest = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $dba->id,
    ]);

    expect(fn () => app(ApprovalService::class)->approve($ownRequest, $dba))
        ->toThrow(AuthorizationException::class);

    expect($ownRequest->fresh()->status)->toBe(QueryRequestStatus::Pending);
});

test('admins can override the self-approval block', function () {
    Queue::fake();
    Notification::fake();

    [$team] = approvalSetup();

    $admin = User::factory()->create(['is_admin' => true]);
    $adminRequest = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $admin->id,
    ]);

    app(ApprovalService::class)->approve($adminRequest, $admin);

    expect($adminRequest->fresh()->status)->toBe(QueryRequestStatus::Queued);

    $log = AuditLog::where('action', 'request.approved')->latest('id')->first();
    expect($log->metadata['override'])->toBeTrue();
});

test('developers cannot review requests', function () {
    [, , $developer, $request] = approvalSetup();

    expect(fn () => app(ApprovalService::class)->approve($request, $developer))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($developer)->get(route('approvals.index'))->assertForbidden();
});

test('non-pending requests cannot be reviewed again', function () {
    Queue::fake();
    Notification::fake();

    [, $dba, , $request] = approvalSetup();

    app(ApprovalService::class)->approve($request, $dba);

    expect(fn () => app(ApprovalService::class)->reject($request->fresh(), $dba, 'nope'))
        ->toThrow(AuthorizationException::class);
});
