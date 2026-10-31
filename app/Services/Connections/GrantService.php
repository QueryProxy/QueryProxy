<?php

namespace App\Services\Connections;

use App\Enums\AuditEvent;
use App\Enums\GrantAccessLevel;
use App\Enums\GrantRequestStatus;
use App\Enums\TeamRole;
use App\Models\Connection;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Models\User;
use App\Notifications\GrantDecided;
use App\Notifications\GrantRequested;
use App\Services\Chat\ChatNotifier;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Every change to who may use a connection goes through here: the DBA's
 * direct grant and revoke, a developer's request for time-bound access and
 * its approval or rejection, and the removal of grants whose time has run
 * out (ADR-025). The rules and the audit records live in one place for the
 * web UI and the scheduled job alike.
 *
 * Access ends at `expires_at` because the access check ignores an expired
 * grant (Connection::grantedUsers()); expireDue() only removes the row later
 * and records `grant.expired`. A grant gives the right to submit requests;
 * each request still needs its own approval (ADR-010).
 */
class GrantService
{
    public const MIN_DURATION_MINUTES = 1;

    /** 30 days; anything longer should be a permanent grant a DBA gives on purpose. */
    public const MAX_DURATION_MINUTES = 43200;

    private const MAX_TEXT_LENGTH = 1000;

    /**
     * A DBA gives a developer access directly. With `$expiresAt` the grant ends
     * then; without, it is permanent. An existing row for the pair (also an
     * expired one the job has not removed yet) is replaced.
     *
     * @throws AuthorizationException when $grantedBy may not manage the connection
     * @throws GrantException when the user is no developer of the team or the end time is not in the future
     */
    public function grant(
        Connection $connection,
        User $developer,
        User $grantedBy,
        ?CarbonInterface $expiresAt = null,
        GrantAccessLevel $level = GrantAccessLevel::Write,
        ?string $reason = null,
    ): void {
        Gate::forUser($grantedBy)->authorize('update', $connection);

        if (! $developer->isDeveloperIn($connection->team)) {
            throw new GrantException('Only developers of the connection\'s team can be granted access.');
        }

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new GrantException('A grant must end in the future.');
        }

        $expiresAt = $this->inAppTimezone($expiresAt);
        $reason = $this->cleanText($reason);

        DB::transaction(function () use ($connection, $developer, $grantedBy, $expiresAt, $level, $reason) {
            $replaced = $this->writeGrant($connection, $developer, $grantedBy, $expiresAt, $level, $reason);

            audit()->record(AuditEvent::ConnectionGrantAdded, actor: $grantedBy, connection: $connection, metadata: [
                'developer' => $developer->email,
                'access_level' => $level->value,
                'expires_at' => $expiresAt?->toIso8601String(),
                'reason' => $reason,
                'replaced' => $replaced,
            ]);
        }, attempts: 3);
    }

    /**
     * Take the developer's access away now. Returns false when there was
     * nothing to remove.
     *
     * @throws AuthorizationException when $revokedBy may not manage the connection
     */
    public function revoke(Connection $connection, User $developer, User $revokedBy): bool
    {
        Gate::forUser($revokedBy)->authorize('update', $connection);

        return DB::transaction(function () use ($connection, $developer, $revokedBy) {
            $grant = $this->lockedGrant($connection, $developer);

            if ($grant === null) {
                return false;
            }

            $grant->delete();

            audit()->record(AuditEvent::ConnectionGrantRevoked, actor: $revokedBy, connection: $connection, metadata: [
                'developer' => $developer->email,
                'access_level' => $grant->access_level->value,
                'expires_at' => $grant->expires_at?->toIso8601String(),
                'was_expired' => $grant->expires_at !== null && $grant->expires_at->isPast(),
            ]);

            return true;
        }, attempts: 3);
    }

    /**
     * A developer asks for access to a connection for a period.
     *
     * @throws AuthorizationException when the requester is no active developer of the connection's team
     * @throws GrantException when the period or reason is invalid, a request is already open, or the
     *                        requester already holds permanent access that covers the request
     */
    public function request(
        User $requester,
        Connection $connection,
        GrantAccessLevel $level,
        int $durationMinutes,
        string $reason,
    ): ConnectionGrantRequest {
        if (! $requester->isDeveloperIn($connection->team) || $requester->isSuspended()) {
            throw new AuthorizationException('Only active developers of the connection\'s team can request access.');
        }

        $this->assertDuration($durationMinutes);

        $reason = $this->cleanText($reason);

        if ($reason === null) {
            throw new GrantException('Say why you need the access.');
        }

        $grantRequest = DB::transaction(function () use ($requester, $connection, $level, $durationMinutes, $reason) {
            // Same row lock as UserLifecycle::delete: two simultaneous requests
            // of one user cannot both pass the "nothing open yet" check.
            User::whereKey($requester->id)->lockForUpdate()->first();

            $open = ConnectionGrantRequest::pending()
                ->where('connection_id', $connection->id)
                ->where('user_id', $requester->id)
                ->exists();

            if ($open) {
                throw new GrantException('You already have an open access request for this connection.');
            }

            $this->assertFitsCurrentGrant($connection->grantFor($requester), $level);

            $grantRequest = ConnectionGrantRequest::create([
                'team_id' => $connection->team_id,
                'connection_id' => $connection->id,
                'user_id' => $requester->id,
                'access_level' => $level,
                'duration_minutes' => $durationMinutes,
                'reason' => $reason,
                'status' => GrantRequestStatus::Pending,
            ]);

            audit()->record(AuditEvent::GrantRequested, actor: $requester, connection: $connection, metadata: [
                'grant_request_id' => $grantRequest->id,
                'access_level' => $level->value,
                'duration_minutes' => $durationMinutes,
                'reason' => $reason,
            ]);

            return $grantRequest;
        }, attempts: 3);

        $this->notifyRequested($grantRequest);

        return $grantRequest;
    }

    /**
     * Approve the request. The grant starts now and lasts the requested period.
     * When the developer already holds a grant of the same level it is only
     * extended, never shortened or made permanent; a request for another level
     * than the grant in force is refused (ADR-025), also here.
     *
     * @throws AuthorizationException when $decider may not decide, or the request is their own
     * @throws GrantException when the request is already decided, the requester can no longer receive access,
     *                        or the grant in force has another level or is permanent
     */
    public function approve(ConnectionGrantRequest $grantRequest, User $decider): ConnectionGrantRequest
    {
        $this->authorizeDecision($grantRequest, $decider);

        DB::transaction(function () use ($grantRequest, $decider) {
            $this->claim($grantRequest, $decider, GrantRequestStatus::Approved, null);

            $connection = $grantRequest->connection;
            $developer = User::withTrashed()->whereKey($grantRequest->user_id)->lockForUpdate()->first();

            if ($developer === null || $developer->trashed() || $developer->isSuspended() || ! $developer->isDeveloperIn($connection->team)) {
                throw new GrantException('The requester is no longer an active developer of this team; reject the request instead.');
            }

            $level = $grantRequest->access_level;

            // Read under a row lock, so a DBA who narrows or revokes the grant
            // at the same moment is waited for, not overwritten. An expired
            // row the job has not removed yet counts as no grant.
            $row = $this->lockedGrant($connection, $developer);
            $current = $row !== null && ($row->isPermanent() || $row->expires_at->isFuture()) ? $row : null;

            // The grant may have changed since the request was filed.
            $this->assertFitsCurrentGrant($current, $level);

            // Same level: the approval only extends the end time. It never
            // shortens a grant, and never turns a timed grant permanent.
            $expiresAt = $this->expiryAfter($grantRequest->duration_minutes);
            $extends = $current === null || $expiresAt->gt($current->expires_at);

            if ($extends && $current !== null) {
                $this->extendGrant($current, $decider, $expiresAt, $grantRequest->reason);
            } elseif ($extends) {
                $this->writeGrant($connection, $developer, $decider, $expiresAt, $level, $grantRequest->reason);
            } else {
                $expiresAt = $current->expires_at;
            }

            audit()->record(AuditEvent::GrantApproved, actor: $decider, connection: $connection, metadata: [
                'grant_request_id' => $grantRequest->id,
                'developer' => $developer->email,
                'access_level' => $level->value,
                'duration_minutes' => $grantRequest->duration_minutes,
                'expires_at' => $expiresAt->toIso8601String(),
                'extended' => $extends,
            ]);
        }, attempts: 3);

        return $this->afterDecision($grantRequest, withGrant: true);
    }

    /**
     * @throws AuthorizationException when $decider may not decide, or the request is their own
     * @throws GrantException when the request is already decided
     */
    public function reject(ConnectionGrantRequest $grantRequest, User $decider, ?string $note = null): ConnectionGrantRequest
    {
        $this->authorizeDecision($grantRequest, $decider);

        $note = $this->cleanText($note);

        DB::transaction(function () use ($grantRequest, $decider, $note) {
            $this->claim($grantRequest, $decider, GrantRequestStatus::Rejected, $note);

            audit()->record(AuditEvent::GrantRejected, actor: $decider, connection: $grantRequest->connection, metadata: [
                'grant_request_id' => $grantRequest->id,
                'developer' => $grantRequest->requester->email,
                'reason' => $note,
            ]);
        }, attempts: 3);

        return $this->afterDecision($grantRequest);
    }

    /**
     * Remove every grant whose end time has passed and record `grant.expired`
     * for each. Access already ended at `expires_at`; this is cleanup.
     *
     * @return int number of grants removed
     */
    public function expireDue(): int
    {
        $removed = 0;

        ConnectionGrant::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->eachById(function (ConnectionGrant $grant) use (&$removed) {
                $removed += DB::transaction(function () use ($grant) {
                    // Conditional on the end time: a DBA who extended the grant
                    // since the scan keeps it.
                    $deleted = ConnectionGrant::query()
                        ->whereKey($grant->getKey())
                        ->where('expires_at', '<=', now())
                        ->delete();

                    if ($deleted !== 1) {
                        return 0;
                    }

                    audit()->record(
                        AuditEvent::GrantExpired,
                        connection: Connection::find($grant->connection_id),
                        system: true,
                        metadata: [
                            'developer' => User::withTrashed()->find($grant->user_id)?->email,
                            'access_level' => $grant->access_level->value,
                            'expires_at' => $grant->expires_at->toIso8601String(),
                            'granted_by' => $grant->granted_by,
                        ],
                    );

                    return 1;
                }, attempts: 3);
            });

        return $removed;
    }

    /**
     * The end time of a grant that starts now and lasts $minutes.
     *
     * @throws GrantException when the period is outside the allowed range
     */
    public function expiryAfter(int $minutes): Carbon
    {
        $this->assertDuration($minutes);

        return now()->addMinutes($minutes);
    }

    /** Insert or replace the pair's row; returns true when a row existed. */
    private function writeGrant(
        Connection $connection,
        User $developer,
        User $grantedBy,
        ?CarbonInterface $expiresAt,
        GrantAccessLevel $level,
        ?string $reason,
    ): bool {
        $grant = $this->lockedGrant($connection, $developer);
        $replaced = $grant !== null;

        $grant ??= new ConnectionGrant(['connection_id' => $connection->id, 'user_id' => $developer->id]);

        $grant->forceFill([
            'expires_at' => $expiresAt,
            'granted_by' => $grantedBy->id,
            'reason' => $reason,
            'access_level' => $level,
        ])->save();

        return $replaced;
    }

    /**
     * Move a timed grant's end time forward. The update is conditional on the
     * level and end time that were read: where row locks do nothing (SQLite)
     * a concurrent change makes it match no row, and the approval stops
     * instead of overwriting the DBA's change.
     *
     * @throws GrantException when the grant changed meanwhile
     */
    private function extendGrant(ConnectionGrant $current, User $grantedBy, CarbonInterface $expiresAt, string $reason): void
    {
        $updated = ConnectionGrant::query()
            ->whereKey($current->getKey())
            ->where('access_level', $current->access_level->value)
            ->where('expires_at', $current->expires_at)
            ->update([
                'expires_at' => $expiresAt,
                'granted_by' => $grantedBy->id,
                'reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new GrantException('The grant was changed while the request was being approved; review it and try again.');
        }
    }

    private function lockedGrant(Connection $connection, User $developer): ?ConnectionGrant
    {
        return ConnectionGrant::query()
            ->where('connection_id', $connection->id)
            ->where('user_id', $developer->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Only a DBA of the team (or the system admin) decides, and never on their
     * own request (ADR-008): a developer promoted to DBA while a request of
     * theirs was open cannot approve it either.
     */
    private function authorizeDecision(ConnectionGrantRequest $grantRequest, User $decider): void
    {
        Gate::forUser($decider)->authorize('update', $grantRequest->connection);

        if ($grantRequest->user_id === $decider->id) {
            throw new AuthorizationException('You cannot decide your own access request.');
        }
    }

    /** Move a pending request to its final status; the conditional update lets only one decision win. */
    private function claim(ConnectionGrantRequest $grantRequest, User $decider, GrantRequestStatus $status, ?string $note): void
    {
        $claimed = ConnectionGrantRequest::query()
            ->whereKey($grantRequest->id)
            ->pending()
            ->update([
                'status' => $status,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new GrantException('This request has already been decided.');
        }
    }

    private function afterDecision(ConnectionGrantRequest $grantRequest, bool $withGrant = false): ConnectionGrantRequest
    {
        $grantRequest = $grantRequest->fresh(['connection', 'requester', 'decider']);

        // After an approval the messages state the grant as stored, not the period asked for.
        $grant = $withGrant ? $grantRequest->connection->grantFor($grantRequest->requester) : null;

        // A deleted requester has no inbox left.
        if (! $grantRequest->requester->trashed()) {
            $grantRequest->requester->notify(new GrantDecided($grantRequest, $grant));
        }

        app(ChatNotifier::class)->grantDecided($grantRequest, $grant);

        return $grantRequest;
    }

    private function notifyRequested(ConnectionGrantRequest $grantRequest): void
    {
        $grantRequest->load(['connection', 'requester', 'team']);

        $dbas = $grantRequest->team->users()
            ->wherePivot('role', TeamRole::Dba->value)
            ->where('users.id', '!=', $grantRequest->user_id)
            ->whereNull('users.suspended_at')
            ->get();

        Notification::send($dbas, new GrantRequested($grantRequest));

        app(ChatNotifier::class)->grantRequested($grantRequest);
    }

    /**
     * A request must not change a grant in force (ADR-025): one row holds a
     * pair's grant, so a different level would replace it, and a permanent
     * grant of the same level leaves nothing to ask for.
     *
     * @throws GrantException
     */
    private function assertFitsCurrentGrant(?ConnectionGrant $current, GrantAccessLevel $level): void
    {
        if ($current === null) {
            return;
        }

        if ($current->access_level !== $level) {
            throw new GrantException(sprintf(
                'The developer already has %s access to this connection; a %s request would replace it. A DBA changes the existing grant directly.',
                $current->access_level->value,
                $level->value,
            ));
        }

        if ($current->isPermanent()) {
            throw new GrantException(sprintf('The developer already has permanent %s access to this connection.', $level->value));
        }
    }

    private function assertDuration(int $minutes): void
    {
        if ($minutes < self::MIN_DURATION_MINUTES || $minutes > self::MAX_DURATION_MINUTES) {
            throw new GrantException(sprintf(
                'The period must be between %d minute and %d days.',
                self::MIN_DURATION_MINUTES,
                intdiv(self::MAX_DURATION_MINUTES, 1440),
            ));
        }
    }

    /** Trimmed free text, null when empty; refuses text over the limit. */
    private function cleanText(?string $text): ?string
    {
        $text = trim((string) $text);

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new GrantException('The text may be at most '.self::MAX_TEXT_LENGTH.' characters long.');
        }

        return $text === '' ? null : $text;
    }

    private function inAppTimezone(?CarbonInterface $time): ?Carbon
    {
        return $time === null ? null : Carbon::instance($time)->setTimezone(config('app.timezone'));
    }
}
