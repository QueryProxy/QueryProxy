<?php

use App\Enums\QueryRequestStatus;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\Show;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * The "Waiting for slot" badge is derived from the deferral audit entry written by
 * LimitConnectionConcurrency; it has no status of its own and disappears once
 * the request leaves the queue.
 */
function slotBadgeRequest(QueryRequestStatus $status, bool $deferred): array
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $developer->id,
        'status' => $status,
    ]);

    if ($deferred) {
        audit()->record(QueryRequest::SLOT_DEFERRED_ACTION, actor: $developer, request: $request, metadata: [
            'reason' => 'connection_slots_busy',
            'slots' => 2,
        ]);
    }

    return [$developer, $request];
}

test('a queued request that was held back for a slot shows the badge on its page and in the list', function () {
    [$developer, $request] = slotBadgeRequest(QueryRequestStatus::Queued, deferred: true);

    expect($request->isWaitingForSlot())->toBeTrue();

    Livewire::actingAs($developer)->test(Show::class, ['queryRequest' => $request])->assertSee('Waiting for slot');
    Livewire::actingAs($developer)->test(Index::class)->assertSee('Waiting for slot');
});

test('a queued request that was never deferred shows no badge', function () {
    [$developer, $request] = slotBadgeRequest(QueryRequestStatus::Queued, deferred: false);

    expect($request->isWaitingForSlot())->toBeFalse();

    Livewire::actingAs($developer)->test(Show::class, ['queryRequest' => $request])->assertDontSee('Waiting for slot');
    Livewire::actingAs($developer)->test(Index::class)->assertDontSee('Waiting for slot');
});

test('the badge goes away once the request starts running or reaches a final state', function (QueryRequestStatus $status) {
    [$developer, $request] = slotBadgeRequest(QueryRequestStatus::Queued, deferred: true);

    $request->update(['status' => $status]);

    expect($request->fresh()->isWaitingForSlot())->toBeFalse()
        // The deferral entry stays in the history; only the badge is gone.
        ->and(AuditLog::where('query_request_id', $request->id)->where('action', QueryRequest::SLOT_DEFERRED_ACTION)->count())->toBe(1);

    Livewire::actingAs($developer)->test(Show::class, ['queryRequest' => $request->fresh()])->assertDontSee('Waiting for slot');
    Livewire::actingAs($developer)->test(Index::class)->assertDontSee('Waiting for slot');
})->with([
    'running' => [QueryRequestStatus::Running],
    'completed' => [QueryRequestStatus::Completed],
    'failed' => [QueryRequestStatus::Failed],
    'cancelled' => [QueryRequestStatus::Cancelled],
]);

test('the list works out the badge for several requests without a query per row', function () {
    [$developer, $first] = slotBadgeRequest(QueryRequestStatus::Queued, deferred: true);
    QueryRequest::factory()->count(3)->create([
        'team_id' => $first->team_id,
        'connection_id' => $first->connection_id,
        'user_id' => $developer->id,
        'status' => QueryRequestStatus::Queued,
    ]);

    DB::enableQueryLog();
    Livewire::actingAs($developer)->test(Index::class);
    // The existence check rides along inside the list query; a per-row lookup would add one query per request.
    $auditQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'audit_logs'));
    DB::disableQueryLog();

    expect($auditQueries)->toHaveCount(1);
});
