<?php

namespace App\Services\Scim;

use App\Enums\AuditEvent;
use App\Models\ScimGroup;
use App\Models\User;
use App\Services\Auth\OidcRoleSync;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Groups resource of SCIM (ADR-026).
 *
 * QueryProxy keeps no groups of its own; a pushed group is stored so the
 * identity provider can address it again. What it does is feed
 * QUERYPROXY_OIDC_ROLE_MAP, by the same OidcRoleSync an SSO login uses.
 *
 * A map entry names a group the way the OIDC group claim does: by display name
 * (Okta, Keycloak) or by object id (Entra ID, whose SCIM externalId carries the
 * object id). A SCIM group therefore counts under both its displayName and its
 * externalId. When a group changes, only the teams that group maps to are
 * recomputed, for the users whose membership changed; a group the map does not
 * name is accepted and has no effect (ADR-023, ADR-026).
 */
class ScimGroupService
{
    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public function __construct(
        private readonly ScimUserService $users,
        private readonly OidcRoleSync $roles,
    ) {}

    /** @throws ScimException */
    public function find(string $id): ScimGroup
    {
        $group = ctype_digit($id) ? ScimGroup::query()->find((int) $id) : null;

        return $group ?? throw ScimException::notFound("Group {$id} not found.");
    }

    /**
     * @return array<string, mixed> a ListResponse
     *
     * @throws ScimException
     */
    public function list(?string $filter, int $startIndex, int $count, bool $withMembers): array
    {
        $parsed = ScimFilter::parse($filter, ['displayname', 'externalid', 'id']);

        $query = ScimGroup::query();

        if ($parsed !== null) {
            $value = $parsed->value;

            match ($parsed->attribute) {
                'displayname' => $query->whereRaw('LOWER(display_name) = ?', [Str::lower($value)]),
                'externalid' => $query->where('external_id', $value),
                'id' => ctype_digit($value) ? $query->whereKey((int) $value) : $query->whereRaw('1 = 0'),
            };
        }

        $startIndex = max(1, $startIndex);
        $count = max(0, min(ScimUserService::MAX_PAGE, $count));

        $total = (clone $query)->count();
        $groups = $count === 0
            ? collect()
            : $query->orderBy('id')->offset($startIndex - 1)->limit($count)->when($withMembers, fn ($q) => $q->with('users:id,name'))->get();

        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $groups->count(),
            'Resources' => $groups->map(fn (ScimGroup $group) => $this->resource($group, $withMembers))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function resource(ScimGroup $group, bool $withMembers = true): array
    {
        $resource = [
            'schemas' => [self::SCHEMA],
            'id' => (string) $group->id,
        ];

        if ($group->external_id !== null) {
            $resource['externalId'] = $group->external_id;
        }

        $resource['displayName'] = $group->display_name;

        if ($withMembers) {
            $resource['members'] = $group->loadMissing('users:id,name')->users->sortBy('id')->values()
                ->map(fn (User $user) => [
                    'value' => (string) $user->id,
                    'display' => $user->name,
                    '$ref' => route('scim.users.show', ['id' => $user->id]),
                ])->all();
        }

        $resource['meta'] = [
            'resourceType' => 'Group',
            'created' => $group->created_at?->toIso8601String(),
            'lastModified' => $group->updated_at?->toIso8601String(),
            'location' => route('scim.groups.show', ['id' => $group->id]),
        ];

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function create(array $body): ScimGroup
    {
        $name = ScimValue::string($body['displayName'] ?? null, 'displayName')
            ?? throw ScimException::invalidValue('"displayName" is required.');

        $memberIds = $this->memberIds($body['members'] ?? []);

        $this->assertNameFree($name, null);

        try {
            $group = DB::transaction(function () use ($body, $name, $memberIds) {
                $group = ScimGroup::query()->create([
                    'display_name' => $name,
                    'external_id' => ScimValue::string($body['externalId'] ?? null, 'externalId'),
                ]);

                $group->users()->sync($memberIds);

                ScimAudit::record(AuditEvent::ScimGroupSynced, [
                    'operation' => 'created',
                    'group' => $group->display_name,
                    'members_added' => count($memberIds),
                    'members_removed' => 0,
                ]);

                return $group;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ScimException::uniqueness("A group named {$name} already exists.");
        }

        $this->syncRoles($memberIds, $this->keysOf($group));

        return $group;
    }

    /**
     * PUT: name, external id and members are replaced.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function replace(ScimGroup $group, array $body): ScimGroup
    {
        $name = ScimValue::string($body['displayName'] ?? null, 'displayName')
            ?? throw ScimException::invalidValue('"displayName" is required.');

        return $this->update(
            $group,
            $name,
            ScimValue::string($body['externalId'] ?? null, 'externalId'),
            [['replace', $this->memberIds($body['members'] ?? [])]],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function patch(ScimGroup $group, array $body): ScimGroup
    {
        $name = null;
        $externalId = false;
        $memberOps = [];

        foreach (ScimPatch::operations($body) as $operation) {
            $this->collect($operation['op'], $operation['path'], $operation['value'], $name, $externalId, $memberOps);
        }

        return $this->update($group, $name, $externalId, $memberOps);
    }

    /** Deletes the group; its members' roles are recomputed without it. */
    public function delete(ScimGroup $group): void
    {
        $memberIds = [];
        $keys = [];

        DB::transaction(function () use ($group, &$memberIds, &$keys) {
            // The same lock an update takes, so a member added meanwhile is seen here.
            $group = $this->lock($group);

            $keys = $this->keysOf($group);
            $memberIds = $group->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

            $group->users()->detach();
            $group->delete();

            ScimAudit::record(AuditEvent::ScimGroupSynced, [
                'operation' => 'deleted',
                'group' => $group->display_name,
                'members_added' => 0,
                'members_removed' => count($memberIds),
            ]);
        }, attempts: 3);

        $this->syncRoles($memberIds, $keys);
    }

    /**
     * @param  string|false|null  $externalId  false = leave as is
     * @param  list<array{0: string, 1: list<int>|null}>  $memberOps  [verb, user ids]; null ids on remove = everyone
     */
    private function update(ScimGroup $group, ?string $name, string|false|null $externalId, array $memberOps): ScimGroup
    {
        if ($name !== null && $name !== $group->display_name) {
            $this->assertNameFree($name, $group->id);
        }

        $affected = [];
        $keys = [];

        try {
            DB::transaction(function () use ($group, $name, $externalId, $memberOps, &$affected, &$keys) {
                // The row is locked first: two updates of one group (an identity
                // provider sends several PATCHes at once) take turns, and the
                // second one reads the members the first one left.
                $locked = $this->lock($group);

                $keysBefore = $this->keysOf($locked);
                $before = $locked->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
                $current = $before;

                foreach ($memberOps as [$verb, $ids]) {
                    $current = match ($verb) {
                        'add' => array_values(array_unique([...$current, ...$ids])),
                        'remove' => $ids === null ? [] : array_values(array_diff($current, $ids)),
                        'replace' => $ids,
                    };
                }

                $added = array_values(array_diff($current, $before));
                $removed = array_values(array_diff($before, $current));

                // Only the difference is written, never the whole list.
                if ($added !== []) {
                    $locked->users()->attach($added);
                }

                if ($removed !== []) {
                    $locked->users()->detach($removed);
                }

                $attributes = [];

                if ($name !== null) {
                    $attributes['display_name'] = $name;
                }

                if ($externalId !== false) {
                    $attributes['external_id'] = $externalId;
                }

                $locked->forceFill($attributes)->save();

                $keysAfter = $this->keysOf($locked);
                $keys = array_values(array_unique([...$keysBefore, ...$keysAfter]));

                // The display name and external id are what the role map matches,
                // so changing either changes the roles of every member.
                $renamed = $keysBefore !== $keysAfter;

                $affected = [...$added, ...$removed, ...($renamed ? $current : [])];

                ScimAudit::record(AuditEvent::ScimGroupSynced, [
                    'operation' => 'updated',
                    'group' => $locked->display_name,
                    'members_added' => count($added),
                    'members_removed' => count($removed),
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ScimException::uniqueness('A group with that name already exists.');
        }

        $this->syncRoles($affected, $keys);

        return $group->refresh();
    }

    /** The group row, read again under a write lock. */
    private function lock(ScimGroup $group): ScimGroup
    {
        return ScimGroup::query()->whereKey($group->id)->lockForUpdate()->first()
            ?? throw ScimException::notFound("Group {$group->id} not found.");
    }

    /**
     * The names the role map may know the group by: display name and external id.
     *
     * @return list<string>
     */
    private function keysOf(ScimGroup $group): array
    {
        return array_values(array_filter([$group->display_name, $group->external_id], fn (?string $key) => $key !== null && $key !== ''));
    }

    /**
     * @param  list<array{0: string, 1: list<int>|null}>  $memberOps
     */
    private function collect(string $op, ?string $path, mixed $value, ?string &$name, string|false|null &$externalId, array &$memberOps): void
    {
        if ($path === null) {
            if (! is_array($value)) {
                throw ScimException::invalidValue('An operation without "path" needs an object as "value".');
            }

            foreach ($value as $key => $item) {
                $this->collect($op, (string) $key, $item, $name, $externalId, $memberOps);
            }

            return;
        }

        $path = ScimPatch::normalize($path);

        if ($path === 'displayname') {
            if ($op !== 'remove') {
                $name = ScimValue::string($value, 'displayName')
                    ?? throw ScimException::invalidValue('"displayName" cannot be empty.');
            }

            return;
        }

        if ($path === 'externalid') {
            $externalId = $op === 'remove' ? null : ScimValue::string($value, 'externalId');

            return;
        }

        // members, members[value eq "7"]
        if (preg_match('/^members(?:\[\s*value\s+eq\s+"([^"]*)"\s*\])?$/', $path, $match) !== 1) {
            return;
        }

        $filtered = $match[1] ?? null;

        if ($filtered !== null) {
            if ($op === 'remove') {
                $memberOps[] = ['remove', $this->memberIds([['value' => $filtered]], strict: false)];
            }

            return;
        }

        if ($op === 'remove') {
            // Okta lists the members to remove as the value; without one, all go.
            $memberOps[] = ['remove', $value === null ? null : $this->memberIds($value, strict: false)];

            return;
        }

        $memberOps[] = [$op, $this->memberIds($value ?? [])];
    }

    /**
     * The ids of the users a "members" list names. When $strict, a member that
     * is not a user SCIM can see is an error; on removal it is simply skipped.
     *
     * @return list<int>
     *
     * @throws ScimException
     */
    private function memberIds(mixed $members, bool $strict = true): array
    {
        if ($members === null || $members === []) {
            return [];
        }

        if (! is_array($members) || ! array_is_list($members)) {
            // A single member object is tolerated.
            $members = is_array($members) ? [$members] : throw ScimException::invalidValue('"members" must be a list.');
        }

        $requested = [];

        foreach ($members as $member) {
            $id = is_array($member) ? ($member['value'] ?? null) : null;

            if (! is_string($id) && ! is_int($id)) {
                throw ScimException::invalidValue('Every member needs a "value" with the user id.');
            }

            $requested[] = (string) $id;
        }

        $requested = array_values(array_unique($requested));
        $numeric = array_values(array_filter($requested, 'ctype_digit'));

        // One query per chunk, not one per member.
        $known = [];

        foreach (array_chunk($numeric, 500) as $chunk) {
            foreach ($this->users->visible()->whereIn('id', array_map('intval', $chunk))->pluck('id') as $id) {
                $known[(int) $id] = true;
            }
        }

        $ids = [];

        foreach ($requested as $id) {
            if (! ctype_digit($id) || ! isset($known[(int) $id])) {
                if ($strict) {
                    throw ScimException::invalidValue('Member '.Str::limit($id, 40).' is not a known user.');
                }

                continue;
            }

            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /** @throws ScimException */
    private function assertNameFree(string $name, ?int $exceptGroupId): void
    {
        $query = ScimGroup::query()->whereRaw('LOWER(display_name) = ?', [Str::lower($name)]);

        if ($exceptGroupId !== null) {
            $query->whereKeyNot($exceptGroupId);
        }

        if ($query->exists()) {
            throw ScimException::uniqueness("A group named {$name} already exists.");
        }
    }

    /**
     * Recomputes the team roles of the users whose membership changed. Only
     * the teams that the changed group maps to are looked at, and only when
     * the map knows the group (by display name or external id) at all; each
     * user's role there follows every SCIM group they are in.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $groupKeys  display names and external ids of the group before and after the change
     */
    private function syncRoles(array $userIds, array $groupKeys): void
    {
        if ($userIds === []) {
            return;
        }

        $mapped = array_values(array_intersect($groupKeys, array_column($this->roles->roleMap(), 'group')));

        if ($mapped === []) {
            return;
        }

        // One transaction per user, the user row locked first: two requests
        // that touch the same user's groups take turns, and the one that runs
        // last reads the memberships the other already committed. Reading the
        // groups before the lock would let a stale read overwrite the newer
        // role. The retry belongs to this outermost transaction (see
        // UserLifecycle::delete()); the read is inside it, so a retry reads again.
        foreach (array_values(array_unique($userIds)) as $userId) {
            DB::transaction(function () use ($userId, $mapped) {
                $user = User::query()->whereKey($userId)->lockForUpdate()->first();

                if ($user === null) {
                    return;
                }

                $keys = [];

                $rows = DB::table('scim_group_user')
                    ->join('scim_groups', 'scim_groups.id', '=', 'scim_group_user.scim_group_id')
                    ->where('scim_group_user.user_id', $userId)
                    ->get(['scim_groups.display_name', 'scim_groups.external_id']);

                foreach ($rows as $row) {
                    $keys[] = (string) $row->display_name;

                    if ($row->external_id !== null && $row->external_id !== '') {
                        $keys[] = (string) $row->external_id;
                    }
                }

                $this->roles->sync($user, array_values(array_unique($keys)), 'scim', $mapped);
            }, attempts: 3);
        }
    }
}
