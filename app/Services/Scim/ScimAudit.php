<?php

namespace App\Services\Scim;

use App\Enums\AuditEvent;
use App\Models\ScimToken;

/**
 * Audit records written for a SCIM call. The caller is a token, not a user, so
 * the record has no actor; metadata.via and metadata.token_id say who did it.
 */
final class ScimAudit
{
    /** The binding the middleware sets to the token that authenticated the call. */
    public const TOKEN_BINDING = 'scim.token';

    /** @param  array<string, mixed>  $metadata */
    public static function record(AuditEvent $event, array $metadata = []): void
    {
        $token = app()->bound(self::TOKEN_BINDING) ? app(self::TOKEN_BINDING) : null;

        audit()->record($event, metadata: ['via' => 'scim']
            + ($token instanceof ScimToken ? ['token_id' => $token->id] : [])
            + $metadata);
    }
}
