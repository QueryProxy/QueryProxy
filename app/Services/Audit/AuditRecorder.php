<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Models\User;

class AuditRecorder
{
    public function record(
        string $action,
        ?Team $team = null,
        ?User $actor = null,
        ?QueryRequest $request = null,
        ?Connection $connection = null,
        ?string $sql = null,
        array $metadata = [],
    ): AuditLog {
        $actor ??= auth()->user();

        return AuditLog::create([
            'team_id' => $team?->id ?? $request?->team_id ?? $connection?->team_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'query_request_id' => $request?->id,
            'connection_id' => $connection?->id ?? $request?->connection_id,
            'sql' => $sql,
            'metadata' => $metadata ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
