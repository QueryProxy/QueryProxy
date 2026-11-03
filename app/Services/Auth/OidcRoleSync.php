<?php

namespace App\Services\Auth;

use App\Enums\AuditEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns the identity provider groups of a user into team roles (ADR-023).
 *
 * QUERYPROXY_OIDC_ROLE_MAP lists group:team:role entries. At every login the
 * memberships in the teams the map names are made to match the groups: added,
 * changed or removed, each change audited. Teams the map does not name are
 * never touched, so memberships given by hand there survive.
 */
class OidcRoleSync
{
    /** When one team is reached by several groups, the highest role wins. */
    private const ROLE_RANK = [
        TeamRole::Dba->value => 3,
        TeamRole::Developer->value => 2,
        TeamRole::Auditor->value => 1,
    ];

    /**
     * Groups listed in the configured claim, and whether the claim was there.
     *
     * @param  array<string, mixed>  $claims
     * @return array{0: list<string>, 1: bool}
     */
    public function groupsFrom(array $claims): array
    {
        $value = OidcClient::claim($claims, (string) config('queryproxy.oidc.group_claim', 'groups'));

        if ($value === null) {
            return [[], false];
        }

        $items = is_array($value) ? $value : [$value];

        $groups = [];
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $groups[] = $item;
            }
        }

        return [array_values(array_unique($groups)), true];
    }

    /**
     * The parsed QUERYPROXY_OIDC_ROLE_MAP. An entry is group:team:role, split
     * from the right so a group name may itself contain colons; a malformed
     * entry is logged and skipped.
     *
     * @return list<array{group: string, team: string, role: TeamRole}>
     */
    public function roleMap(): array
    {
        $entries = [];

        foreach (explode(',', (string) config('queryproxy.oidc.role_map', '')) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $parts = explode(':', $raw);
            $role = TeamRole::tryFrom(strtolower(trim((string) array_pop($parts))));
            $team = trim((string) array_pop($parts));
            $group = trim(implode(':', $parts));

            if ($role === null || $team === '' || $group === '') {
                Log::warning('OIDC role map entry ignored: expected group:team:role with role dba, developer or auditor.', ['entry' => $raw]);

                continue;
            }

            $entries[] = ['group' => $group, 'team' => $team, 'role' => $role];
        }

        return $entries;
    }

    /**
     * Applies the map for one login, or, for SCIM (ADR-026), for the groups
     * the identity provider put the user in. $via names that other source: the
     * audit records then carry metadata.via and no actor, since the user did
     * not change their own roles.
     *
     * $onlyGroups narrows a SCIM sync to the teams that the listed groups map
     * to (ADR-026): a membership change of one group must not recompute, and so
     * remove, the roles other groups or an SSO login gave in other teams. The
     * role in each of those teams is still the highest one over all the groups
     * given.
     *
     * @param  list<string>  $groups
     * @param  list<string>|null  $onlyGroups
     * @return list<array{change: string, team: string, from: ?string, to: ?string}> what changed
     */
    public function sync(User $user, array $groups, ?string $via = null, ?array $onlyGroups = null): array
    {
        $map = $this->roleMap();

        if ($map === []) {
            return [];
        }

        if ($via === null && array_intersect(array_column($map, 'group'), $groups) === []) {
            // Not an error: the user is simply in none of the mapped groups.
            // Their memberships in the mapped teams are removed below.
            Log::info('OIDC login with no group from the role map; no team role is granted.', ['user_id' => $user->id, 'groups_received' => count($groups)]);
        }

        /** @var array<int, Team> $teams */
        $teams = [];
        /** @var array<int, TeamRole> $desired */
        $desired = [];

        $resolved = [];

        foreach ($map as $entry) {
            $team = $this->findTeam($entry['team']);

            if ($team === null) {
                Log::warning('OIDC role map names a team that does not exist.', ['team' => $entry['team']]);

                continue;
            }

            $resolved[] = [$team, $entry];
        }

        $scope = null;

        if ($onlyGroups !== null) {
            $scope = [];

            foreach ($resolved as [$team, $entry]) {
                if (in_array($entry['group'], $onlyGroups, true)) {
                    $scope[$team->id] = true;
                }
            }
        }

        foreach ($resolved as [$team, $entry]) {
            if ($scope !== null && ! isset($scope[$team->id])) {
                continue;
            }

            $teams[$team->id] = $team;

            if (! in_array($entry['group'], $groups, true)) {
                continue;
            }

            $current = $desired[$team->id] ?? null;
            if ($current === null || self::ROLE_RANK[$entry['role']->value] > self::ROLE_RANK[$current->value]) {
                $desired[$team->id] = $entry['role'];
            }
        }

        $changes = [];

        DB::transaction(function () use ($user, $teams, $desired, $via, &$changes) {
            // A retried attempt starts over from the database state.
            $changes = [];

            foreach ($teams as $teamId => $team) {
                $existing = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $user->id)->value('role');
                $wanted = ($desired[$teamId] ?? null)?->value;

                if ($existing === $wanted) {
                    continue;
                }

                if ($wanted === null) {
                    $user->teams()->detach($teamId);
                    $change = 'removed';
                } elseif ($existing === null) {
                    $user->teams()->attach($teamId, ['role' => $wanted]);
                    $change = 'added';
                } else {
                    $user->teams()->updateExistingPivot($teamId, ['role' => $wanted]);
                    $change = 'changed';
                }

                $changes[] = ['change' => $change, 'team' => $team->slug, 'from' => $existing, 'to' => $wanted];

                audit()->record(AuditEvent::UserRoleSynced, team: $team, actor: $via === null ? $user : null, metadata: [
                    'user' => $user->email,
                    'change' => $change,
                    'team' => $team->slug,
                    'from' => $existing,
                    'to' => $wanted,
                ] + ($via === null ? [] : ['via' => $via]));
            }
        }, attempts: 3);

        $user->unsetRelation('teams');

        return $changes;
    }

    private function findTeam(string $reference): ?Team
    {
        return Team::query()->where('slug', $reference)->first()
            ?? Team::query()->where('name', $reference)->first();
    }
}
