<?php

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\Sinks\AuditEventDispatcher;
use Throwable;

class AuditRecorder
{
    public function record(
        AuditEvent|string $action,
        ?Team $team = null,
        ?User $actor = null,
        ?QueryRequest $request = null,
        ?Connection $connection = null,
        ?string $sql = null,
        array $metadata = [],
    ): AuditLog {
        $actor ??= auth()->user();

        $log = AuditLog::create([
            'team_id' => $team?->id ?? $request?->team_id ?? $connection?->team_id,
            'user_id' => $actor?->id,
            'action' => $action instanceof AuditEvent ? $action->value : $action,
            'query_request_id' => $request?->id,
            'connection_id' => $connection?->id ?? $request?->connection_id,
            'sql' => $sql,
            'metadata' => $metadata ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        // Hand the stored record to the SIEM sinks (ADR-021). A sink problem,
        // even one in resolving the dispatcher, must never fail the audit write.
        try {
            app(AuditEventDispatcher::class)->dispatch($log);
        } catch (Throwable $e) {
            report($e);
        }

        return $log;
    }
}
