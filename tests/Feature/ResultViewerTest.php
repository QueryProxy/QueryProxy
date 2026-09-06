<?php

use App\Livewire\Results\Viewer;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function completedRequest(int $rows = 120): array
{
    Storage::fake('local');

    $team = Team::factory()->create();
    $requester = User::factory()->create();
    $team->users()->attach($requester, ['role' => 'developer']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $path = 'results/'.$team->id.'/test.ndjson';
    $lines = [];

    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = json_encode([$i, sprintf('name-%04d', $i)]);
    }

    Storage::disk('local')->put($path, implode("\n", $lines)."\n");

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'user_id' => $requester->id,
        'status' => 'completed',
        'result_disk' => 'local',
        'result_path' => $path,
        'result_row_count' => $rows,
        'result_columns' => ['id', 'name'],
        'executed_at' => now(),
    ]);

    return [$request, $requester, $team];
}

test('the viewer pages through stored results', function () {
    [$request, $requester] = completedRequest(120);

    $component = Livewire::actingAs($requester)->test(Viewer::class, ['queryRequest' => $request]);

    $component->assertSee('name-0001')->assertDontSee('name-0051');

    $component->call('nextPage')->assertSee('name-0051')->assertDontSee('name-0001');

    expect($component->get('page'))->toBe(2)
        ->and($component->instance()->lastPage())->toBe(3);
});

test('csv download streams the full result with headers', function () {
    [$request, $requester] = completedRequest(60);

    $response = $this->actingAs($requester)->get(route('requests.download', $request));

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toStartWith("id,name\n")
        ->and(substr_count($csv, "\n"))->toBe(61)
        ->and($csv)->toContain('60,name-0060');
});

test('users without view access cannot download results', function () {
    [$request] = completedRequest(5);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('requests.download', $request))->assertForbidden();
});

test('auditors can view but strangers cannot', function () {
    [$request, , $team] = completedRequest(5);

    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);

    Livewire::actingAs($auditor)->test(Viewer::class, ['queryRequest' => $request])->assertSee('name-0001');
});

test('results:prune removes expired files and clears pointers', function () {
    [$request] = completedRequest(5);

    $request->update(['executed_at' => now()->subDays(45)]);

    $this->artisan('results:prune')->assertSuccessful();

    $request->refresh();

    expect($request->result_path)->toBeNull()
        ->and(Storage::disk('local')->exists('results/'.$request->team_id.'/test.ndjson'))->toBeFalse();
});

test('results:prune keeps fresh files', function () {
    [$request] = completedRequest(5);

    $request->update(['executed_at' => now()->subDays(2)]);

    $this->artisan('results:prune')->assertSuccessful();

    expect($request->fresh()->result_path)->not->toBeNull();
});
