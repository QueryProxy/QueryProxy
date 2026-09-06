<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;

test('audit logs cannot be updated', function () {
    $log = audit()->record('test.event', metadata: ['k' => 'v']);

    expect(fn () => $log->update(['action' => 'tampered']))
        ->toThrow(RuntimeException::class);
});

test('audit logs cannot be deleted', function () {
    $log = audit()->record('test.event');

    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
});

test('recorder captures actor and team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user);

    $log = audit()->record('team.created', team: $team);

    expect($log->user_id)->toBe($user->id)
        ->and($log->team_id)->toBe($team->id)
        ->and(AuditLog::count())->toBe(1);
});
