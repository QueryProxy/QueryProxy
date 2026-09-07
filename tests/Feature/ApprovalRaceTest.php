<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

function pendingRequest(): array
{
    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'status' => QueryRequestStatus::Pending,
    ]);

    return [$request, $dba];
}

test('a second concurrent approval is rejected and dispatches the job only once', function () {
    Queue::fake();

    [$request, $dba] = pendingRequest();
    $service = app(ApprovalService::class);

    $service->approve($request, $dba);

    // A stale copy of the model still reads "pending"; the conditional update
    // must refuse it rather than dispatching a duplicate execution job.
    $stale = QueryRequest::find($request->id);
    $stale->setAttribute('status', QueryRequestStatus::Pending);

    expect(fn () => $service->approve($stale, $dba))
        ->toThrow(AuthorizationException::class);

    Queue::assertPushed(ExecuteQueryRequest::class, 1);
});

test('rejecting an already-approved request is refused', function () {
    Queue::fake();

    [$request, $dba] = pendingRequest();
    $service = app(ApprovalService::class);

    $service->approve($request, $dba);

    $stale = QueryRequest::find($request->id);
    $stale->setAttribute('status', QueryRequestStatus::Pending);

    expect(fn () => $service->reject($stale, $dba, 'no'))
        ->toThrow(AuthorizationException::class);
});
