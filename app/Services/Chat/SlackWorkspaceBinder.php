<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Models\ChatIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Binds the Slack identities that have no workspace yet (made before identities
 * were bound, or left unassigned by the upgrade backfill) to one Slack
 * workspace in a single step.
 *
 * Until it runs such an identity matches nothing: that default does not
 * change. The admin names the workspace, so this is a statement that every
 * unbound Slack member id belongs to it; each identity gets its own audit
 * entry.
 */
class SlackWorkspaceBinder
{
    /** A Slack workspace id: `T` and upper-case letters or digits. */
    public const WORKSPACE_PATTERN = '/^T[A-Z0-9_]{2,31}$/';

    public static function normalize(string $workspaceId): ?string
    {
        $workspaceId = strtoupper(trim($workspaceId));

        return preg_match(self::WORKSPACE_PATTERN, $workspaceId) === 1 ? $workspaceId : null;
    }

    /** How many identities bind() would change: the Slack ones without a workspace. */
    public function unboundCount(): int
    {
        return $this->unbound()->count();
    }

    /**
     * @return int the number of identities bound
     */
    public function bind(string $workspaceId, ?User $actor = null): int
    {
        $workspaceId = self::normalize($workspaceId)
            ?? throw new InvalidArgumentException('Not a Slack workspace id (expected T followed by letters and digits).');

        return DB::transaction(function () use ($workspaceId, $actor) {
            $bound = 0;

            $this->unbound()->with('user')->lockForUpdate()->eachById(function (ChatIdentity $identity) use ($workspaceId, $actor, &$bound) {
                $identity->forceFill(['team_id' => $workspaceId])->save();

                audit()->record(
                    'chat_identity.linked',
                    actor: $actor,
                    system: $actor === null,
                    metadata: [
                        'user' => $identity->user?->email,
                        'provider' => ChatProvider::Slack->value,
                        'external_id' => $identity->external_id,
                        'slack_team_id' => $workspaceId,
                        'source' => 'workspace_bind',
                    ],
                );

                $bound++;
            });

            return $bound;
        });
    }

    private function unbound(): Builder
    {
        return ChatIdentity::query()
            ->where('provider', ChatProvider::Slack->value)
            ->whereNull('team_id');
    }
}
