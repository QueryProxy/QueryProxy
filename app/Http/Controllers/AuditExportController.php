<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam() ?? abort(403);

        $query = AuditLog::query()
            ->where('team_id', $team->id)
            ->with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('actor'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', '%'.$request->string('actor').'%')
                ->orWhere('name', 'like', '%'.$request->string('actor').'%')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->string('from').' 00:00:00'))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->string('to').' 23:59:59'))
            ->latest('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['timestamp', 'action', 'actor', 'query_request_id', 'connection_id', 'ip', 'sql', 'metadata']);

            $query->lazyById(500)->each(function (AuditLog $log) use ($out) {
                fputcsv($out, [
                    $log->created_at->toIso8601String(),
                    $log->action,
                    $log->user?->email,
                    $log->query_request_id,
                    $log->connection_id,
                    $log->ip,
                    $log->sql,
                    $log->metadata ? json_encode($log->metadata) : null,
                ]);
            });

            fclose($out);
        }, 'queryproxy-audit.csv', ['Content-Type' => 'text/csv']);
    }
}
