<?php

use App\Enums\QueryRequestStatus;
use App\Livewire\Requests\Index;
use App\Livewire\Requests\Show;
use App\Livewire\Studio\QueryStudio;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\QueryRequestSubmitted;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function studioSetup(): array
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    return [$team, $developer, $connection];
}

test('a developer can submit a guarded select', function () {
    [$team, $developer, $connection] = studioSetup();

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT * FROM customers')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $request = QueryRequest::first();

    expect($request)->not->toBeNull()
        ->and($request->sql_prepared)->toBe('SELECT * FROM customers LIMIT 1000')
        ->and($request->status)->toBe(QueryRequestStatus::Pending)
        ->and($request->type->value)->toBe('read')
        ->and($request->user_id)->toBe($developer->id)
        ->and($request->team_id)->toBe($team->id);
});

test('guard violations block submission', function () {
    [, $developer, $connection] = studioSetup();

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'DELETE FROM customers')
        ->call('submit');

    expect(QueryRequest::count())->toBe(0);
});

test('developers cannot submit through ungranted connections', function () {
    [$team, $developer] = studioSetup();
    $other = Connection::factory()->create(['team_id' => $team->id]);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $other->id)
        ->set('sql', 'SELECT 1')
        ->call('submit')
        ->assertForbidden();

    expect(QueryRequest::count())->toBe(0);
});

test('submission notifies team dbas', function () {
    Notification::fake();

    [$team, $developer, $connection] = studioSetup();
    $dba = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);

    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1')
        ->call('submit');

    Notification::assertSentTo($dba, QueryRequestSubmitted::class);
});

test('requesters can cancel while pending', function () {
    [, $developer, $connection] = studioSetup();
    $request = QueryRequest::factory()->create([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
        'user_id' => $developer->id,
    ]);

    Livewire::actingAs($developer)
        ->test(Show::class, ['queryRequest' => $request])
        ->call('cancel');

    expect($request->fresh()->status)->toBe(QueryRequestStatus::Cancelled);
});

test('strangers cannot view a request', function () {
    [, , $connection] = studioSetup();
    $request = QueryRequest::factory()->create([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
    ]);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('requests.show', $request))->assertForbidden();
});

test('developers only see their own requests in the list', function () {
    [$team, $developer, $connection] = studioSetup();
    $mine = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'user_id' => $developer->id,
    ]);
    $other = QueryRequest::factory()->create([
        'team_id' => $team->id, 'connection_id' => $connection->id, 'title' => 'SOMEBODY-ELSES-REQUEST',
    ]);

    Livewire::actingAs($developer)
        ->test(Index::class)
        ->assertSee('#'.$mine->id)
        ->assertDontSee('SOMEBODY-ELSES-REQUEST');
});
