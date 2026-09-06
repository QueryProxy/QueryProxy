<?php

use App\Livewire\Audit\Index;
use App\Livewire\Studio\QueryStudio;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function auditSetup(): array
{
    $team = Team::factory()->create();
    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);
    session(['current_team_id' => $team->id]);

    return [$team, $auditor];
}

test('the audit page is auditor-only', function () {
    [$team, $auditor] = auditSetup();

    $developer = User::factory()->create();
    $team->users()->attach($developer, ['role' => 'developer']);

    $this->actingAs($auditor)->get(route('audit.index'))->assertOk();
    $this->actingAs($developer)->get(route('audit.index'))->assertForbidden();
});

test('audit entries are scoped to the current team', function () {
    [$team, $auditor] = auditSetup();
    $otherTeam = Team::factory()->create();

    AuditLog::create(['team_id' => $team->id, 'action' => 'request.approved']);
    AuditLog::create(['team_id' => $otherTeam->id, 'action' => 'secret.other-team-event']);

    Livewire::actingAs($auditor)
        ->test(Index::class)
        ->assertSee('request.approved')
        ->assertDontSee('secret.other-team-event');
});

test('audit entries can be filtered by action', function () {
    [$team, $auditor] = auditSetup();

    AuditLog::create(['team_id' => $team->id, 'action' => 'request.approved']);
    AuditLog::create(['team_id' => $team->id, 'action' => 'connection.created']);

    // The action dropdown always lists every action, so assert on the table cells.
    Livewire::actingAs($auditor)
        ->test(Index::class)
        ->set('action', 'request.')
        ->assertSeeHtml('text-xs">request.approved</code>')
        ->assertDontSeeHtml('text-xs">connection.created</code>');
});

test('audit csv export streams filtered rows', function () {
    [$team, $auditor] = auditSetup();
    $actor = User::factory()->create(['email' => 'dba@corp.test']);

    AuditLog::create(['team_id' => $team->id, 'user_id' => $actor->id, 'action' => 'request.approved', 'sql' => 'SELECT 1']);
    AuditLog::create(['team_id' => $team->id, 'action' => 'auth.login']);

    $response = $this->actingAs($auditor)->get(route('audit.export', ['action' => 'request.']));

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('request.approved')
        ->and($csv)->toContain('dba@corp.test')
        ->and($csv)->not->toContain('auth.login');
});

test('a full request lifecycle leaves a reconstructable trail', function () {
    Notification::fake();
    Http::fake();
    Storage::fake('local');

    $team = Team::factory()->create();
    $dba = User::factory()->create();
    $developer = User::factory()->create();
    $team->users()->attach($dba, ['role' => 'dba']);
    $team->users()->attach($developer, ['role' => 'developer']);

    $connection = Connection::factory()
        ->sqlite(tempnam(sys_get_temp_dir(), 'qp_audit_'))
        ->create(['team_id' => $team->id]);
    $connection->grantedUsers()->attach($developer);
    session(['current_team_id' => $team->id]);

    // Submit → approve → execute (sync).
    Livewire::actingAs($developer)
        ->test(QueryStudio::class)
        ->set('connectionId', $connection->id)
        ->set('sql', 'SELECT 1 AS one')
        ->call('submit');

    $request = QueryRequest::first();

    $this->actingAs($dba);
    app(ApprovalService::class)->approve($request, $dba);

    $actions = AuditLog::where('team_id', $team->id)->pluck('action');

    expect($actions)->toContain('request.submitted')
        ->and($actions)->toContain('request.approved')
        ->and($actions)->toContain('request.execution_started')
        ->and($actions)->toContain('request.execution_completed');
});
