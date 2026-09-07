<?php

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Livewire\Dashboard;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function dashboardSetup(): array
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

test('the tiles count only the statuses in this team', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();
    $otherTeam = Team::factory()->create();

    foreach ([QueryRequestStatus::Pending, QueryRequestStatus::Pending, QueryRequestStatus::Completed] as $status) {
        QueryRequest::factory()->create([
            'team_id' => $team->id,
            'connection_id' => $connection->id,
            'user_id' => $developer->id,
            'status' => $status->value,
        ]);
    }

    QueryRequest::factory()->create(['team_id' => $otherTeam->id, 'status' => 'pending']);

    Livewire::actingAs($dba)
        ->test(Dashboard::class)
        ->assertViewHas('counts', fn ($counts) => $counts['pending'] === 2 && $counts['completed'] === 1);
});

test('a developer only sees their own requests', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();
    $otherDeveloper = User::factory()->create();
    $team->users()->attach($otherDeveloper, ['role' => 'developer']);

    $mine = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
    ]);
    $theirs = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $otherDeveloper->id,
    ]);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSee('#'.$mine->id)
        ->assertDontSee('#'.$theirs->id);
});

test('a tile filters the recent list and clicking it again clears the filter', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();

    $pending = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
        'status' => 'pending',
    ]);
    $completed = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
        'status' => 'completed',
    ]);

    // As the developer: a DBA would also see the pending request in the
    // "Awaiting your decision" panel, which is not what this test is about.
    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->call('filterBy', 'completed')
        ->assertSet('filter', 'completed')
        ->assertSee('#'.$completed->id)
        ->assertDontSee('#'.$pending->id)
        ->call('filterBy', 'completed')
        ->assertSet('filter', '')
        ->assertSee('#'.$pending->id);
});

test('the decision panel is hidden from developers', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();

    QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
    ]);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->assertSet('filter', '')
        ->assertDontSee('Awaiting your decision');

    Livewire::actingAs($dba)
        ->test(Dashboard::class)
        ->assertSee('Awaiting your decision');
});

test('a dba is never offered their own request to decide', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();

    $own = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $dba->id,
    ]);
    $theirs = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
    ]);

    Livewire::actingAs($dba)
        ->test(Dashboard::class)
        ->assertViewHas('awaiting', fn ($awaiting) => $awaiting->pluck('id')->all() === [$theirs->id]);
});

test('a dba can approve straight from the dashboard', function () {
    Queue::fake();
    Notification::fake();

    [$team, $dba, $developer, $connection] = dashboardSetup();

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
    ]);

    Livewire::actingAs($dba)
        ->test(Dashboard::class)
        ->call('approve', $request->id);

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Queued);
    Queue::assertPushed(ExecuteQueryRequest::class);
});

test('a developer cannot approve from the dashboard', function () {
    [$team, $dba, $developer, $connection] = dashboardSetup();

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $dba->id,
    ]);

    Livewire::actingAs($developer)
        ->test(Dashboard::class)
        ->call('approve', $request->id)
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Pending);
});
