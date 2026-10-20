<?php

use App\Livewire\Audit\Index;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

const AUDIT_RAW_SQL = "SELECT * FROM users WHERE email = 'ada@example.com' AND ssn = 123456789";
const AUDIT_UNPARSEABLE_SQL = "SELECT * FROM users WHERE token = 'hunter2";

/** @return array{Team, User, Connection} */
function auditRedactionSetup(): array
{
    $team = Team::factory()->create();
    $auditor = User::factory()->create();
    $team->users()->attach($auditor, ['role' => 'auditor']);
    session(['current_team_id' => $team->id]);

    return [$team, $auditor, Connection::factory()->create(['team_id' => $team->id])];
}

test('the audit log view shows redacted SQL and never the raw statement', function () {
    [$team, $auditor, $connection] = auditRedactionSetup();

    // Written the way every pre-existing row was: raw sql only, no redacted copy anywhere.
    $log = AuditLog::create([
        'team_id' => $team->id, 'connection_id' => $connection->id,
        'action' => 'request.submitted', 'sql' => AUDIT_RAW_SQL,
    ]);

    Livewire::actingAs($auditor)
        ->test(Index::class)
        ->call('toggle', $log->id)
        ->assertSee('SELECT * FROM users WHERE email = ? AND ssn = ?')
        ->assertDontSee('ada@example.com')
        ->assertDontSee('123456789');
});

test('the audit log view shows no SQL when it cannot be redacted', function () {
    [$team, $auditor, $connection] = auditRedactionSetup();

    $log = AuditLog::create([
        'team_id' => $team->id, 'connection_id' => $connection->id,
        'action' => 'request.submitted', 'sql' => AUDIT_UNPARSEABLE_SQL,
    ]);

    Livewire::actingAs($auditor)
        ->test(Index::class)
        ->call('toggle', $log->id)
        ->assertSee('SQL hidden')
        ->assertDontSee('hunter2')
        ->assertDontSee('SELECT * FROM users');
});

test('the audit log view redacts for a system admin too', function () {
    [$team, , $connection] = auditRedactionSetup();
    $admin = User::factory()->create(['is_admin' => true]);

    $log = AuditLog::create([
        'team_id' => $team->id, 'connection_id' => $connection->id,
        'action' => 'request.submitted', 'sql' => AUDIT_RAW_SQL,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('toggle', $log->id)
        ->assertSee('email = ? AND ssn = ?')
        ->assertDontSee('ada@example.com');
});

test('the audit csv carries only sql_redacted, old records included', function () {
    [$team, $auditor, $connection] = auditRedactionSetup();

    AuditLog::create(['team_id' => $team->id, 'connection_id' => $connection->id, 'action' => 'request.submitted', 'sql' => AUDIT_RAW_SQL]);
    AuditLog::create(['team_id' => $team->id, 'connection_id' => $connection->id, 'action' => 'request.approved', 'sql' => AUDIT_UNPARSEABLE_SQL]);
    AuditLog::create(['team_id' => $team->id, 'action' => 'auth.login']);

    $csv = $this->actingAs($auditor)->get(route('audit.export'))->assertOk()->streamedContent();

    expect($csv)
        ->toContain('sql_redacted')
        ->toContain('email = ? AND ssn = ?')
        ->toContain('[SQL hidden]')
        ->not->toContain('ada@example.com')
        ->not->toContain('123456789')
        ->not->toContain('hunter2')
        ->not->toContain(',sql,');
});

test('the audit csv redacts for a system admin on the system scope', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    AuditLog::create(['team_id' => null, 'action' => 'user.updated', 'sql' => AUDIT_RAW_SQL]);

    $csv = $this->actingAs($admin)->get(route('audit.export', ['scope' => 'system']))->assertOk()->streamedContent();

    expect($csv)->toContain('email = ? AND ssn = ?')->not->toContain('ada@example.com');
});

test('AuditLog exposes sql_redacted for SIEM export without touching the stored row', function () {
    [$team, , $connection] = auditRedactionSetup();

    $log = AuditLog::create(['team_id' => $team->id, 'connection_id' => $connection->id, 'action' => 'request.submitted', 'sql' => AUDIT_RAW_SQL]);
    $none = AuditLog::create(['team_id' => $team->id, 'action' => 'auth.login']);
    $bad = AuditLog::create(['team_id' => $team->id, 'action' => 'request.submitted', 'sql' => AUDIT_UNPARSEABLE_SQL]);

    expect($log->fresh()->sql_redacted)->toBe('SELECT * FROM users WHERE email = ? AND ssn = ?')
        ->and($log->fresh()->sql)->toBe(AUDIT_RAW_SQL)
        ->and($none->fresh()->sql_redacted)->toBeNull()
        ->and($none->fresh()->sqlIsHidden())->toBeFalse()
        ->and($bad->fresh()->sql_redacted)->toBeNull()
        ->and($bad->fresh()->sqlIsHidden())->toBeTrue();
});

test('the audit log reads the SQL by the connection driver', function () {
    [$team] = auditRedactionSetup();
    $mysql = Connection::factory()->create(['team_id' => $team->id, 'driver' => 'mysql']);
    $pgsql = Connection::factory()->create(['team_id' => $team->id, 'driver' => 'pgsql']);

    $mysqlLog = AuditLog::create(['team_id' => $team->id, 'connection_id' => $mysql->id, 'action' => 'a', 'sql' => 'SELECT "secret"']);
    $pgsqlLog = AuditLog::create(['team_id' => $team->id, 'connection_id' => $pgsql->id, 'action' => 'a', 'sql' => 'SELECT "col"']);

    expect($mysqlLog->fresh()->sql_redacted)->toBe('SELECT ?')
        ->and($pgsqlLog->fresh()->sql_redacted)->toBe('SELECT "col"');
});
