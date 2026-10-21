<?php

use App\Livewire\Approvals\Index as ApprovalsIndex;
use App\Livewire\Requests\Show;
use App\Livewire\Studio\QueryStudio;
use App\Models\Connection;
use App\Models\MaskingRule;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Masking\SensitiveFilterDetector;
use Livewire\Livewire;

function sensitiveFilterSetup(): array
{
    $team = Team::factory()->create();
    $developer = User::factory()->create();
    $dba = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);
    $team->users()->attach($dba, ['role' => 'dba']);
    $connection = Connection::factory()->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    return [$team, $developer, $dba, $connection];
}

function submitSensitiveFilterQuery(User $developer, Connection $connection, string $sql): QueryRequest
{
    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', $sql)
        ->call('submit')
        ->assertHasNoErrors();

    return QueryRequest::query()->latest('id')->firstOrFail();
}

test('SensitiveFilter a masked column in WHERE is stored and shown to the approver', function () {
    [, $developer, $dba, $connection] = sensitiveFilterSetup();

    $request = submitSensitiveFilterQuery($developer, $connection, "SELECT count(*) FROM customers WHERE email = 'ada@example.com'");

    expect($request->sensitive_filter_columns)->toBe(['email']);

    Livewire::actingAs($dba)->test(ApprovalsIndex::class)->assertSee('sensitive filter')->assertSee('Sensitive column in filter');
    Livewire::actingAs($dba)->test(Show::class, ['queryRequest' => $request])->assertSee('Sensitive column in filter');
});

test('SensitiveFilter a masked column in the select list only gives no badge', function () {
    [, $developer, $dba, $connection] = sensitiveFilterSetup();

    $request = submitSensitiveFilterQuery($developer, $connection, 'SELECT email, phone FROM customers WHERE id = 7');

    expect($request->sensitive_filter_columns)->toBe([]);

    Livewire::actingAs($dba)->test(ApprovalsIndex::class)->assertDontSee('sensitive filter')->assertDontSee('Sensitive column in filter');
    Livewire::actingAs($dba)->test(Show::class, ['queryRequest' => $request])->assertDontSee('Sensitive column in filter');
});

test('SensitiveFilter detects JOIN ON, HAVING and ORDER BY use of a masked column', function () {
    [, $developer, , $connection] = sensitiveFilterSetup();

    $request = submitSensitiveFilterQuery(
        $developer,
        $connection,
        'SELECT a.id FROM a JOIN b ON a.email = b.email GROUP BY a.id HAVING max(a.phone) > 1 ORDER BY a.api_key',
    );

    expect($request->sensitive_filter_columns)->toBe(['email', 'phone', 'api_key']);
});

test('SensitiveFilter only counts enabled column rules of the team and connection', function () {
    [$team, , , $connection] = sensitiveFilterSetup();
    $team->maskingRules()->delete();

    $other = Connection::factory()->create(['team_id' => $team->id]);
    MaskingRule::factory()->create(['team_id' => $team->id, 'pattern' => '*iban*|*vat_id*']);
    MaskingRule::factory()->create(['team_id' => $team->id, 'pattern' => '*salary*', 'enabled' => false]);
    MaskingRule::factory()->create(['team_id' => $team->id, 'connection_id' => $other->id, 'pattern' => '*ssn*']);
    MaskingRule::factory()->create(['team_id' => $team->id, 'match_type' => 'regex', 'pattern' => '/age/']);

    $sql = 'SELECT 1 FROM t WHERE iban = 1 AND VAT_ID = 2 AND salary = 3 AND ssn = 4 AND age = 5';
    $detector = app(SensitiveFilterDetector::class);

    expect($detector->detect($sql, $connection))->toBe(['iban', 'vat_id'])
        ->and($detector->detect($sql, $other))->toBe(['iban', 'vat_id', 'ssn']);
});

test('SensitiveFilter a request without masking rules stores an empty list', function () {
    [$team, $developer, , $connection] = sensitiveFilterSetup();
    $team->maskingRules()->delete();

    $request = submitSensitiveFilterQuery($developer, $connection, "SELECT 1 FROM t WHERE email = 'x'");

    expect($request->sensitive_filter_columns)->toBe([]);
});

test('SensitiveFilter a detector failure does not block the submission', function () {
    [, $developer, , $connection] = sensitiveFilterSetup();

    $this->mock(SensitiveFilterDetector::class)->shouldReceive('detect')->andThrow(new RuntimeException('boom'));

    $request = submitSensitiveFilterQuery($developer, $connection, "SELECT 1 FROM t WHERE email = 'x'");

    expect($request->sensitive_filter_columns)->toBeNull();
});

test('SensitiveFilter a request from before the column existed shows no badge', function () {
    [$team, , $dba, $connection] = sensitiveFilterSetup();
    $request = QueryRequest::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'sensitive_filter_columns' => null]);

    Livewire::actingAs($dba)->test(Show::class, ['queryRequest' => $request])->assertDontSee('Sensitive column in filter');
});
