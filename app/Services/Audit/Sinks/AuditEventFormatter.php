<?php

namespace App\Services\Audit\Sinks;

use App\Models\AuditLog;

/**
 * Turns an audit record into the exported event described by
 * `docs/audit-event.schema.json` (ADR-021).
 *
 * The raw `sql` column is never exported, only `sql_redacted` (ADR-009
 * amendment 2026-10-20). The chain hashes (`hash`, `prev_hash`) are not
 * exported either: they are an unsalted SHA-256 over the raw SQL, so next to
 * `sql_redacted` they would let a receiver brute-force the literals back.
 * Every key is always present, null when it has no
 * value, so a receiver can rely on one shape.
 *
 * Changing or removing a field needs a new SCHEMA_VERSION; adding an optional
 * one does not.
 */
class AuditEventFormatter
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function format(AuditLog $log): array
    {
        $log->loadMissing(['user', 'connection']);

        $action = (string) $log->action;
        $sqlHidden = $log->sqlIsHidden();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (int) $log->id,
            'timestamp' => $log->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'instance' => $this->instance(),
            'action' => $action,
            'category' => explode('.', $action, 2)[0],
            'actor' => $log->user_id === null ? null : [
                'id' => (int) $log->user_id,
                'email' => $log->user?->email,
            ],
            'team_id' => $log->team_id === null ? null : (int) $log->team_id,
            'connection_id' => $log->connection_id === null ? null : (int) $log->connection_id,
            'query_request_id' => $log->query_request_id === null ? null : (int) $log->query_request_id,
            'ip' => $log->ip,
            'sql_redacted' => $sqlHidden ? null : $log->sql_redacted,
            'sql_hidden' => $sqlHidden,
            'metadata' => $this->metadata($log->metadata),
        ];
    }

    /**
     * The event as one line of JSON. Unicode stays escaped, so the line is
     * plain ASCII: safe on stdout, in syslog and in any receiver.
     *
     * @param  array<string, mixed>  $event
     */
    public function json(array $event): string
    {
        return json_encode($event, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * The schema promises a JSON object. A list (no string keys) is wrapped so
     * it still encodes as one.
     *
     * @return array<string, mixed>|null
     */
    private function metadata(mixed $metadata): ?array
    {
        if (! is_array($metadata) || $metadata === []) {
            return null;
        }

        return array_is_list($metadata) ? ['values' => $metadata] : $metadata;
    }

    /** Which installation the event came from, for receivers that collect several. */
    private function instance(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
