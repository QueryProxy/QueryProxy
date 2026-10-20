<?php

use App\Livewire\Dashboard;
use App\Livewire\Requests\Index;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

const SHOW_RAW_SQL = "SELECT * FROM users WHERE email = 'ada@example.com' AND ssn = 123456789";
const SHOW_REDACTED_SQL = 'SELECT * FROM users WHERE email = ? AND ssn = ?';

/**
 * A team with every role, plus one request (no title) written by the developer.
 *
 * @return array{User, User, User, User, QueryRequest} developer, dba, auditor, admin, request
 */
function rawSqlSetup(string $sql = SHOW_RAW_SQL): array
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $dba = User::factory()->create();
    $auditor = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $team->users()->attach($developer, ['role' => 'developer']);
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($auditor, ['role' => 'auditor']);
    $team->users()->attach($admin, ['role' => 'dba']);
    session(['current_team_id' => $team->id]);

    $request = QueryRequest::factory()->create([
        'team_id' => $team->id,
        'connection_id' => Connection::factory()->create(['team_id' => $team->id])->id,
        'user_id' => $developer->id,
        'title' => null,
        'sql_original' => $sql,
        'sql_prepared' => $sql,
    ]);

    return [$developer, $dba, $auditor, $admin, $request];
}

test('RequestShow hides raw SQL from the auditor and shows it redacted', function () {
    [, , $auditor, , $request] = rawSqlSetup();

    $this->actingAs($auditor)->get(route('requests.show', $request))
        ->assertOk()
        ->assertSee(SHOW_REDACTED_SQL)
        ->assertDontSee('ada@example.com')
        ->assertDontSee('123456789');
});

test('RequestShow hides the as-submitted copy from the auditor too', function () {
    [, , $auditor, , $request] = rawSqlSetup();
    $request->update(['sql_original' => "SELECT * FROM users WHERE email = 'ada@example.com' -- as typed"]);

    $this->actingAs($auditor)->get(route('requests.show', $request))
        ->assertOk()
        ->assertDontSee('as typed')
        ->assertDontSee('As submitted')
        ->assertDontSee('ada@example.com');
});

test('RequestShow shows no SQL to the auditor when it cannot be redacted', function () {
    [, , $auditor, , $request] = rawSqlSetup("SELECT * FROM users WHERE token = 'hunter2");

    $this->actingAs($auditor)->get(route('requests.show', $request))
        ->assertOk()
        ->assertSee('SQL hidden')
        ->assertDontSee('hunter2')
        ->assertDontSee('SELECT * FROM users');
});

test('RequestShow shows the raw SQL to the requester, the team dba and the admin', function (string $role) {
    [$developer, $dba, , $admin, $request] = rawSqlSetup();
    $viewer = ['developer' => $developer, 'dba' => $dba, 'admin' => $admin][$role];

    $this->actingAs($viewer)->get(route('requests.show', $request))
        ->assertOk()
        ->assertSee('ada@example.com', false)
        ->assertSee('123456789');
})->with(['developer', 'dba', 'admin']);

test('RequestShow does not let a developer read another developer\'s request', function () {
    [, , , , $request] = rawSqlSetup();
    $team = $request->team;
    $other = User::factory()->create();
    $team->users()->attach($other, ['role' => 'developer']);

    $this->actingAs($other)->get(route('requests.show', $request))->assertForbidden();
});

test('RequestShow policy: viewRawSql is the requester, the team dba and the admin only', function () {
    [$developer, $dba, $auditor, $admin, $request] = rawSqlSetup();
    $foreignDba = User::factory()->create();
    Team::factory()->create()->users()->attach($foreignDba, ['role' => 'dba']);

    expect($developer->can('viewRawSql', $request))->toBeTrue()
        ->and($dba->can('viewRawSql', $request))->toBeTrue()
        ->and($admin->can('viewRawSql', $request))->toBeTrue()
        ->and($auditor->can('viewRawSql', $request))->toBeFalse()
        ->and($foreignDba->can('viewRawSql', $request))->toBeFalse();
});

test('RequestShow list summary is redacted for the auditor and raw for the others', function () {
    [$developer, $dba, $auditor, $admin, $request] = rawSqlSetup();

    Livewire::actingAs($auditor)->test(Index::class)
        ->assertSee(SHOW_REDACTED_SQL)
        ->assertDontSee('ada@example.com')
        ->assertDontSee('123456789');

    foreach ([$developer, $dba, $admin] as $viewer) {
        Livewire::actingAs($viewer)->test(Index::class)
            ->assertSee('ada@example.com', false);
    }
});

test('RequestShow list shows a title as is, and no SQL, for the auditor', function () {
    [, , $auditor, , $request] = rawSqlSetup();
    $request->update(['title' => 'Quarterly report']);

    Livewire::actingAs($auditor)->test(Index::class)
        ->assertSee('Quarterly report')
        ->assertDontSee('ada@example.com');
});

test('RequestShow list hides unparseable SQL from the auditor', function () {
    [, , $auditor] = rawSqlSetup("SELECT * FROM users WHERE token = 'hunter2");

    Livewire::actingAs($auditor)->test(Index::class)
        ->assertSee('(SQL hidden)')
        ->assertDontSee('hunter2');
});

test('RequestShow dashboard recent requests are redacted for the auditor', function () {
    [$developer, , $auditor, , $request] = rawSqlSetup();

    Livewire::actingAs($auditor)->test(Dashboard::class)
        ->assertSee(SHOW_REDACTED_SQL)
        ->assertDontSee('ada@example.com');
});
