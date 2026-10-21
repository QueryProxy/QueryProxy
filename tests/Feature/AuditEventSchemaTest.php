<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\Sinks\AuditEventFormatter;
use Tests\Support\JsonSchema;

function auditEventSchema(): array
{
    return JsonSchema::load(base_path('docs/audit-event.schema.json'));
}

/** @return list<string> */
function schemaViolations(AuditLog $log): array
{
    $formatter = app(AuditEventFormatter::class);

    // Validate what receivers get: the event after a JSON round trip.
    $event = json_decode($formatter->json($formatter->format($log)), true);

    return JsonSchema::validate($event, auditEventSchema());
}

test('the schema lists exactly the events in the catalog', function () {
    $schema = auditEventSchema();

    expect($schema['properties']['action']['enum'])->toBe(AuditEvent::values())
        ->and($schema['properties']['category']['enum'])
        ->toBe(array_values(array_unique(array_map(fn (AuditEvent $e) => $e->category(), AuditEvent::cases()))))
        ->and($schema['properties']['schema_version']['const'])->toBe(AuditEventFormatter::SCHEMA_VERSION)
        ->and(array_keys($schema['properties']))->toBe($schema['required']);
});

test('a minimal system event conforms to the schema', function () {
    $log = audit()->record(AuditEvent::AuditCheckpoint);

    expect(schemaViolations($log))->toBe([]);

    $event = app(AuditEventFormatter::class)->format($log);

    expect($event['actor'])->toBeNull()
        ->and($event['sql_redacted'])->toBeNull()
        ->and($event['sql_hidden'])->toBeFalse()
        ->and($event['metadata'])->toBeNull();
});

test('an event with actor, team, connection, SQL and metadata conforms', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $connection = Connection::factory()->create(['team_id' => $team->id]);

    $log = audit()->record(
        AuditEvent::ConnectionCreated,
        team: $team,
        actor: $user,
        connection: $connection,
        sql: "SELECT * FROM users WHERE email = 'a@b.co'",
        metadata: ['name' => 'Ünïcode "quoted" / slash', 'count' => 3, 'nested' => ['ok' => true]],
    );

    expect(schemaViolations($log))->toBe([]);

    $event = app(AuditEventFormatter::class)->format($log);

    expect($event['actor'])->toBe(['id' => $user->id, 'email' => $user->email])
        ->and($event['team_id'])->toBe($team->id)
        ->and($event['connection_id'])->toBe($connection->id)
        ->and($event['sql_redacted'])->not->toContain('a@b.co');
});

test('the chain hashes are not exported', function () {
    $event = app(AuditEventFormatter::class)->format(audit()->record(AuditEvent::AuditCheckpoint));

    expect($event)->not->toHaveKeys(['hash', 'prev_hash'])
        ->and(auditEventSchema()['properties'])->not->toHaveKeys(['hash', 'prev_hash']);
});

test('the raw SQL is never part of the exported event', function () {
    $log = audit()->record(AuditEvent::AuditCheckpoint, sql: "SELECT 'topsecretvalue'");

    $json = app(AuditEventFormatter::class)->json(app(AuditEventFormatter::class)->format($log));

    expect($json)->not->toContain('topsecretvalue')
        ->and(array_key_exists('sql', json_decode($json, true)))->toBeFalse();
});

test('SQL that cannot be redacted is withheld and flagged', function () {
    $log = audit()->record(AuditEvent::AuditCheckpoint, sql: "SELECT 'topsecretvalue' FROM t WHERE x = 'unterminated");

    $event = app(AuditEventFormatter::class)->format($log);

    expect(schemaViolations($log))->toBe([])
        ->and(json_encode($event))->not->toContain('topsecretvalue')
        ->and($event['sql_hidden'] === ($event['sql_redacted'] === null))->toBeTrue();
});

test('a list in metadata is exported as an object', function () {
    $log = audit()->record(AuditEvent::AuditCheckpoint, metadata: ['a', 'b']);

    expect(schemaViolations($log))->toBe([])
        ->and(app(AuditEventFormatter::class)->format($log)['metadata'])->toBe(['values' => ['a', 'b']]);
});

test('every catalog event name is a valid exported action', function () {
    foreach (AuditEvent::cases() as $case) {
        $log = audit()->record($case);

        expect(schemaViolations($log))->toBe([], $case->value);
    }
});

test('the schema rejects events that break the contract', function () {
    $schema = auditEventSchema();
    $valid = app(AuditEventFormatter::class)->format(audit()->record(AuditEvent::AuditCheckpoint));

    $invalid = [
        'unknown action' => ['action' => 'made.up'] + $valid,
        'extra key (raw sql)' => $valid + ['sql' => 'SELECT 1'],
        'wrong version' => ['schema_version' => 2] + $valid,
        'bad timestamp' => ['timestamp' => '2026-10-21 10:00:00'] + $valid,
        'chain hash exported' => $valid + ['hash' => str_repeat('a', 64)],
        'string id' => ['id' => '7'] + $valid,
        'sql_hidden not bool' => ['sql_hidden' => 'no'] + $valid,
    ];

    foreach ($invalid as $label => $event) {
        expect(JsonSchema::validate(json_decode(json_encode($event), true), $schema))->not->toBe([], $label);
    }

    $missing = $valid;
    unset($missing['sql_hidden']);

    expect(JsonSchema::validate($missing, $schema))->not->toBe([]);
});
