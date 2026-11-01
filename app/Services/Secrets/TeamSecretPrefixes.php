<?php

namespace App\Services\Secrets;

use App\Enums\AuditEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Sets which external secret reference prefixes a team may use (ADR-028
 * amendment). QueryProxy reads secrets with one instance-wide identity; this
 * is the bound that keeps a team's connections to that team's own paths.
 * A team without prefixes cannot use references at all.
 *
 * Only a system administrator may change them; the caller checks that. The
 * change is audited.
 */
class TeamSecretPrefixes
{
    public const MAX = 50;

    /**
     * @param  list<string>  $prefixes  raw input, for example `vault://secret/teams/payments/`
     * @return list<string> the normalized prefixes that were stored
     *
     * @throws ValidationException (key `secret_reference_prefixes`) for a prefix that is not valid
     */
    public function set(Team $team, array $prefixes, ?User $actor = null): array
    {
        $normalized = [];

        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix);

            if ($prefix === '') {
                continue;
            }

            try {
                $normalized[] = SecretReference::normalizePrefix($prefix);
            } catch (SecretResolutionException) {
                throw ValidationException::withMessages([
                    'secret_reference_prefixes' => "Invalid prefix \"{$prefix}\". Use vault://<mount>/<path>, aws-sm://<name-prefix> or azure-kv://<vault-name>[/<secret-prefix>] without wildcards, dot segments or a key.",
                ]);
            }
        }

        $normalized = array_values(array_unique($normalized));

        if (count($normalized) > self::MAX) {
            throw ValidationException::withMessages([
                'secret_reference_prefixes' => 'At most '.self::MAX.' prefixes per team.',
            ]);
        }

        $before = $team->secretReferencePrefixes();

        $team->secret_reference_prefixes = $normalized === [] ? null : $normalized;
        $team->save();

        if ($before !== $normalized) {
            audit()->record(AuditEvent::TeamSecretPrefixesChanged, team: $team, actor: $actor, metadata: [
                'before' => $before,
                'after' => $normalized,
            ], system: $actor === null && auth()->guest());
        }

        return $normalized;
    }
}
