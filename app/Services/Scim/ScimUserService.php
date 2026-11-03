<?php

namespace App\Services\Scim;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\Users\UserLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Users resource of SCIM (ADR-026).
 *
 * Which accounts SCIM can see: those it created itself (scim_provisioned_at)
 * and those tied to the identity provider by SSO. A local account is invisible
 * to it, so a SCIM token cannot suspend or rename a break-glass administrator,
 * and a create whose e-mail address belongs to one is refused with a
 * `uniqueness` error instead of taking it over.
 *
 * `active=false` and DELETE suspend (ADR-018); nothing here deletes a user
 * or soft-deletes one (decision D-12).
 */
class ScimUserService
{
    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public const MAX_PAGE = 200;

    public function __construct(private readonly UserLifecycle $lifecycle) {}

    /** @return Builder<User> */
    public function visible(): Builder
    {
        return User::query()->where(fn (Builder $query) => $query
            ->whereNotNull('scim_provisioned_at')
            ->orWhere('auth_provider', User::PROVIDER_OIDC));
    }

    /** @throws ScimException */
    public function find(string $id): User
    {
        $user = ctype_digit($id) ? $this->visible()->whereKey((int) $id)->first() : null;

        return $user ?? throw ScimException::notFound("User {$id} not found.");
    }

    /**
     * @return array<string, mixed> a ListResponse
     *
     * @throws ScimException
     */
    public function list(?string $filter, int $startIndex, int $count): array
    {
        $parsed = ScimFilter::parse($filter, ['username', 'externalid', 'id', 'emails.value']);

        $query = $this->visible();

        if ($parsed !== null) {
            $value = $parsed->value;

            match ($parsed->attribute) {
                'username' => $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(email) = ?', [Str::lower($value)])
                    ->orWhereRaw('LOWER(scim_user_name) = ?', [Str::lower($value)])),
                'emails.value' => $query->whereRaw('LOWER(email) = ?', [Str::lower($value)]),
                'externalid' => $query->where('scim_external_id', $value),
                'id' => ctype_digit($value) ? $query->whereKey((int) $value) : $query->whereRaw('1 = 0'),
            };
        }

        $startIndex = max(1, $startIndex);
        $count = max(0, min(self::MAX_PAGE, $count));

        $total = (clone $query)->count();
        $users = $count === 0
            ? collect()
            : $query->orderBy('id')->offset($startIndex - 1)->limit($count)->get();

        $groups = $this->groupsOf($users->pluck('id')->all());

        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $startIndex,
            'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn (User $user) => $this->resource($user, $groups[$user->id] ?? []))->all(),
        ];
    }

    /**
     * @param  list<array{id: int, display: string}>|null  $groups  null loads them
     * @return array<string, mixed>
     */
    public function resource(User $user, ?array $groups = null): array
    {
        $groups ??= $this->groupsOf([$user->id])[$user->id] ?? [];

        $resource = [
            'schemas' => [self::SCHEMA],
            'id' => (string) $user->id,
        ];

        if ($user->scim_external_id !== null) {
            $resource['externalId'] = $user->scim_external_id;
        }

        return $resource + [
            'userName' => $user->scim_user_name ?? $user->email,
            'name' => ['formatted' => $user->name],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => ! $user->isSuspended(),
            'groups' => array_map(fn (array $group) => [
                'value' => (string) $group['id'],
                'display' => $group['display'],
                '$ref' => route('scim.groups.show', ['id' => $group['id']]),
            ], $groups),
            'meta' => [
                'resourceType' => 'User',
                'created' => $user->created_at?->toIso8601String(),
                'lastModified' => $user->updated_at?->toIso8601String(),
                'location' => route('scim.users.show', ['id' => $user->id]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function create(array $body): User
    {
        $attributes = $this->attributes($body);

        if ($attributes['user_name'] === null && $attributes['email'] === null) {
            throw ScimException::invalidValue('"userName" is required.');
        }

        $email = $attributes['email'] ?? throw ScimException::invalidValue('The user has no usable e-mail address: send it as "userName" or in "emails".');

        $this->assertEmailFree($email, null);
        $this->assertUserNameFree($attributes['user_name'] ?? $email, null);

        try {
            $user = DB::transaction(function () use ($attributes, $email) {
                $user = new User;
                $user->forceFill([
                    'name' => $attributes['name'] ?? Str::limit(Str::before($email, '@'), 255, ''),
                    'email' => $email,
                    'password' => null,
                    'is_admin' => false,
                    'auth_provider' => User::PROVIDER_LOCAL,
                    'scim_provisioned_at' => now(),
                    'scim_external_id' => $attributes['external_id'] ?? null,
                    'scim_user_name' => $this->userNameToStore($attributes['user_name'], $email),
                    'email_verified_at' => now(),
                ])->save();

                ScimAudit::record(AuditEvent::ScimUserProvisioned, ['user' => $user->email]);

                return $user;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ScimException::uniqueness("A user with the e-mail address {$email} already exists.");
        }

        if (($attributes['active'] ?? true) === false) {
            $this->setActive($user, false, 'active_false');
        }

        return $user->refresh();
    }

    /**
     * PUT: the attributes sent replace the stored ones. `active` is left alone
     * when missing, so a client that omits it cannot lift a suspension by accident.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function replace(User $user, array $body): User
    {
        $attributes = $this->attributes($body);

        if ($attributes['user_name'] === null && $attributes['email'] === null) {
            throw ScimException::invalidValue('"userName" is required.');
        }

        $email = $attributes['email'] ?? throw ScimException::invalidValue('The user has no usable e-mail address: send it as "userName" or in "emails".');

        $changes = [
            'email' => $email,
            'user_name' => $this->userNameToStore($attributes['user_name'], $email),
            'external_id' => $attributes['external_id'] ?? null,
        ];

        if ($attributes['name'] !== null) {
            $changes['name'] = $attributes['name'];
        }

        $this->applyChanges($user, $changes);

        if ($attributes['active'] !== null) {
            $this->setActive($user, $attributes['active'], 'active_false');
        }

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ScimException
     */
    public function patch(User $user, array $body): User
    {
        $state = ['changes' => [], 'name' => [], 'active' => null];

        foreach (ScimPatch::operations($body) as $operation) {
            $this->collect($operation['op'], $operation['path'], $operation['value'], $state);
        }

        $changes = $state['changes'];

        if (array_key_exists('user_name', $changes)) {
            $changes['user_name'] = $this->userNameToStore($changes['user_name'], $changes['email'] ?? $user->email);
        }

        if ($state['name'] !== []) {
            $name = $this->mergeName($user, $state['name']);

            if ($name !== '') {
                $changes['name'] = $name;
            }
        }

        $this->applyChanges($user, $changes);

        if ($state['active'] !== null) {
            $this->setActive($user, $state['active'], 'active_false');
        }

        return $user->refresh();
    }

    /** DELETE: suspension, never removal. */
    public function delete(User $user): void
    {
        $this->setActive($user, false, 'delete');
    }

    /**
     * Suspends or lifts the suspension, and says so in the audit log. SCIM
     * lifts only a suspension it set itself (ADR-026): one an administrator
     * set by hand stays until an administrator lifts it.
     */
    public function setActive(User $user, bool $active, string $reason): void
    {
        if ($active) {
            if ($this->lifecycle->unsuspendIfByScim($user)) {
                ScimAudit::record(AuditEvent::ScimUserUnsuspended, ['user' => $user->email]);
            }

            return;
        }

        // Not wrapped in a transaction of ours: suspend() retries its own.
        if ($this->lifecycle->suspend($user, byScim: true)) {
            ScimAudit::record(AuditEvent::ScimUserSuspended, ['user' => $user->email, 'reason' => $reason]);
        }
    }

    /**
     * Writes the changed attributes (keys: email, user_name, name, external_id).
     *
     * @param  array<string, string|null>  $changes
     *
     * @throws ScimException
     */
    private function applyChanges(User $user, array $changes): void
    {
        $columns = [
            'email' => 'email',
            'user_name' => 'scim_user_name',
            'name' => 'name',
            'external_id' => 'scim_external_id',
        ];

        $dirty = [];

        foreach ($changes as $key => $value) {
            if ($key === 'email' && $value === null) {
                continue;
            }

            if ($user->getAttribute($columns[$key]) !== $value) {
                $dirty[$key] = $value;
            }
        }

        if ($dirty === []) {
            return;
        }

        if (isset($dirty['email'])) {
            $this->assertEmailFree($dirty['email'], $user->id);
        }

        if (isset($dirty['email']) || array_key_exists('user_name', $dirty)) {
            $emailAfter = $dirty['email'] ?? $user->email;
            $userNameAfter = array_key_exists('user_name', $dirty) ? ($dirty['user_name'] ?? $emailAfter) : ($user->scim_user_name ?? $emailAfter);

            if (Str::lower($userNameAfter) !== Str::lower($user->scim_user_name ?? $user->email)) {
                $this->assertUserNameFree($userNameAfter, $user->id);
            }
        }

        try {
            DB::transaction(function () use ($user, $dirty, $columns) {
                $user->refresh();

                $user->forceFill(collect($dirty)->mapWithKeys(fn ($value, $key) => [$columns[$key] => $value])->all())->save();

                ScimAudit::record(AuditEvent::ScimUserUpdated, [
                    'user' => $user->email,
                    'changed' => array_keys($dirty),
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ScimException::uniqueness('That e-mail address belongs to another user.');
        }
    }

    /**
     * userName is unique on the server (RFC 7643): no other user has it as an
     * e-mail address or as their own userName, so `userName eq` finds one user.
     *
     * @throws ScimException
     */
    private function assertUserNameFree(string $userName, ?int $exceptUserId): void
    {
        $query = User::withTrashed()->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(email) = ?', [Str::lower($userName)])
            ->orWhereRaw('LOWER(scim_user_name) = ?', [Str::lower($userName)]));

        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        if ($query->exists()) {
            throw ScimException::uniqueness("The userName {$userName} belongs to another user.");
        }
    }

    /** @throws ScimException */
    private function assertEmailFree(string $email, ?int $exceptUserId): void
    {
        // Another user's userName counts too: `userName eq` matches the e-mail
        // address and the stored userName alike, so an address that is another
        // user's userName would make the filter find two users.
        $query = User::withTrashed()->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->orWhereRaw('LOWER(scim_user_name) = ?', [Str::lower($email)]));

        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        if ($query->exists()) {
            throw ScimException::uniqueness("A user with the e-mail address {$email} already exists.");
        }
    }

    /** userName is stored only when it says something the e-mail address does not. */
    private function userNameToStore(?string $userName, string $email): ?string
    {
        return $userName === null || Str::lower($userName) === Str::lower($email) ? null : $userName;
    }

    /**
     * What a create or replace body carries, in our terms.
     *
     * @param  array<string, mixed>  $body
     * @return array{user_name: ?string, email: ?string, name: ?string, external_id: ?string, active: ?bool}
     *
     * @throws ScimException
     */
    private function attributes(array $body): array
    {
        $userName = ScimValue::string($body['userName'] ?? null, 'userName');

        $email = array_key_exists('emails', $body) ? ScimValue::emailFromList($body['emails']) : null;

        if ($email === null && $userName !== null && filter_var($userName, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $userName;
        }

        $name = null;
        $parts = [];

        if (isset($body['name']) && is_array($body['name'])) {
            $parts = $this->nameParts($body['name']);
        }

        if (isset($parts['formatted'])) {
            $name = $parts['formatted'];
        } elseif (isset($parts['given']) || isset($parts['family'])) {
            $name = Str::limit(trim(($parts['given'] ?? '').' '.($parts['family'] ?? '')), 255, '');
        }

        if ($name === null || $name === '') {
            $name = ScimValue::string($body['displayName'] ?? null, 'displayName');
        }

        return [
            'user_name' => $userName,
            'email' => $email,
            'name' => $name === '' ? null : $name,
            'external_id' => ScimValue::string($body['externalId'] ?? null, 'externalId'),
            'active' => array_key_exists('active', $body) ? ScimValue::bool($body['active'], 'active') : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $name
     * @return array{formatted?: ?string, given?: ?string, family?: ?string}
     */
    private function nameParts(array $name): array
    {
        $parts = [];

        foreach ($name as $key => $value) {
            $key = match (strtolower((string) $key)) {
                'formatted' => 'formatted',
                'givenname' => 'given',
                'familyname' => 'family',
                default => null,
            };

            if ($key !== null && (is_string($value) || $value === null)) {
                $parts[$key] = ScimValue::string($value, 'name');
            }
        }

        return $parts;
    }

    /**
     * Folds one PATCH operation into $state. Attributes QueryProxy does not
     * keep (title, phoneNumbers, enterprise extension, ...) are ignored rather
     * than refused: identity providers send them with every sync.
     *
     * @param  array{changes: array<string, string|null>, name: array<string, ?string>, active: ?bool}  $state
     */
    private function collect(string $op, ?string $path, mixed $value, array &$state): void
    {
        if ($path === null) {
            if (! is_array($value)) {
                throw ScimException::invalidValue('An operation without "path" needs an object as "value".');
            }

            foreach ($value as $key => $item) {
                $this->collect($op, (string) $key, $item, $state);
            }

            return;
        }

        $path = ScimPatch::normalize($path);

        if ($path === 'active') {
            if ($op !== 'remove') {
                $state['active'] = ScimValue::bool($value, 'active');
            }

            return;
        }

        if ($path === 'username') {
            if ($op !== 'remove') {
                $state['changes']['user_name'] = ScimValue::string($value, 'userName');
            }

            return;
        }

        if ($path === 'externalid') {
            $state['changes']['external_id'] = $op === 'remove' ? null : ScimValue::string($value, 'externalId');

            return;
        }

        if (in_array($path, ['displayname', 'name.formatted'], true)) {
            if ($op !== 'remove') {
                $state['name']['formatted'] = ScimValue::string($value, 'name.formatted');
            }

            return;
        }

        if (in_array($path, ['name.givenname', 'name.familyname'], true)) {
            if ($op !== 'remove') {
                $state['name'][$path === 'name.givenname' ? 'given' : 'family'] = ScimValue::string($value, $path);
            }

            return;
        }

        if ($path === 'name') {
            if ($op !== 'remove' && is_array($value)) {
                $state['name'] = $this->nameParts($value) + $state['name'];
            }

            return;
        }

        // emails, emails.value, emails[type eq "work"].value, emails[primary eq true]
        if (preg_match('/^emails(\[.*\])?(\.value)?$/', $path) === 1) {
            if ($op === 'remove') {
                return;
            }

            $email = is_array($value) ? ScimValue::emailFromList(array_is_list($value) ? $value : [$value]) : ScimValue::email($value);

            if ($email !== null) {
                $state['changes']['email'] = $email;
            }
        }
    }

    /**
     * @param  array<string, ?string>  $parts
     */
    private function mergeName(User $user, array $parts): string
    {
        if (isset($parts['formatted'])) {
            return $parts['formatted'];
        }

        [$given, $family] = array_pad(explode(' ', $user->name, 2), 2, '');

        $given = array_key_exists('given', $parts) ? ($parts['given'] ?? '') : $given;
        $family = array_key_exists('family', $parts) ? ($parts['family'] ?? '') : $family;

        return Str::limit(trim($given.' '.$family), 255, '');
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<array{id: int, display: string}>>
     */
    private function groupsOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $groups = [];

        $rows = DB::table('scim_group_user')
            ->join('scim_groups', 'scim_groups.id', '=', 'scim_group_user.scim_group_id')
            ->whereIn('scim_group_user.user_id', $userIds)
            ->orderBy('scim_groups.id')
            ->get(['scim_group_user.user_id', 'scim_groups.id', 'scim_groups.display_name']);

        foreach ($rows as $row) {
            $groups[(int) $row->user_id][] = ['id' => (int) $row->id, 'display' => (string) $row->display_name];
        }

        return $groups;
    }
}
