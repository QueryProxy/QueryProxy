<?php

namespace App\Services\Users;

use App\Enums\QueryRequestStatus;
use App\Models\ChatIdentity;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suspension and deletion of user accounts. Neither ever removes request
 * history: deletion is a soft delete and query_requests keep pointing at the
 * user row.
 */
class UserLifecycle
{
    public const DELETED_REASON = 'User deleted';

    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * Block the user from signing in and acting. Live sessions end, chat
     * identity stops resolving. Their pending requests are NOT touched: they
     * stay pending and are decided under the normal rules.
     *
     * $byScim marks the suspension as SCIM's own (ADR-026), which SCIM may
     * lift again. Suspending a user whom SCIM suspended already, by hand, takes
     * the suspension over: from then on only an administrator lifts it.
     *
     * The decision is made on the row as it is under a write lock, never on the
     * caller's copy: an administrator and SCIM suspending the same user at the
     * same moment leave one suspension, and it belongs to whoever got there
     * first (the administrator, if both).
     *
     * @return bool whether this call suspended the user (false: already suspended)
     */
    public function suspend(User $user, bool $byScim = false): bool
    {
        return DB::transaction(function () use ($user, $byScim) {
            $this->lockAndRefresh($user);

            if ($user->isSuspended()) {
                if (! $byScim && $user->suspended_by_scim) {
                    $user->forceFill(['suspended_by_scim' => false])->save();
                }

                return false;
            }

            $user->forceFill(['suspended_at' => now(), 'suspended_by_scim' => $byScim])->save();

            $this->endSessions($user);

            audit()->record('user.suspended', metadata: ['user' => $user->email]);

            return true;
        }, attempts: 3);
    }

    public function unsuspend(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->lockAndRefresh($user);

            if ($user->isSuspended()) {
                $this->lift($user);
            }
        }, attempts: 3);
    }

    /**
     * Lifts the suspension only when SCIM set it (ADR-026), judged on the row
     * under the write lock: an administrator who took the suspension over a
     * moment ago keeps it. Returns whether it was lifted.
     */
    public function unsuspendIfByScim(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $this->lockAndRefresh($user);

            if (! $user->isSuspended() || ! $user->suspended_by_scim) {
                return false;
            }

            $this->lift($user);

            return true;
        }, attempts: 3);
    }

    /**
     * Takes the row's write lock and reads the user again. The lock comes
     * first: a plain read before it could hand the transaction a snapshot that
     * predates the other writer's commit. Retried attempts start over here too.
     */
    private function lockAndRefresh(User $user): void
    {
        User::withTrashed()->whereKey($user->id)->lockForUpdate()->first();

        $user->refresh();
    }

    private function lift(User $user): void
    {
        $user->forceFill(['suspended_at' => null, 'suspended_by_scim' => false])->save();

        audit()->record('user.unsuspended', metadata: ['user' => $user->email]);
    }

    /**
     * Soft delete the user and cancel their pending requests (only those;
     * approved and queued requests keep running) in one transaction: if any
     * cancellation fails the delete rolls back, so a deleted user never keeps a
     * pending request.
     *
     * The transaction writes an audit record, so it is opened with `attempts`:
     * on MariaDB 11.8 (`innodb_snapshot_isolation`) a concurrent audit write
     * makes the chain lock row fail with error 1020, and only the outermost
     * transaction can be retried (see AuditLog::performInsert()).
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Serialise with QueryStudio::submit, which locks the same row: a
            // request submitted meanwhile is either seen below or refused.
            User::whereKey($user->id)->lockForUpdate()->first();

            $cancelled = [];

            QueryRequest::where('user_id', $user->id)
                ->where('status', QueryRequestStatus::Pending)
                ->orderBy('id')
                ->get()
                ->each(function (QueryRequest $request) use (&$cancelled) {
                    if ($this->approvals->cancelBySystem($request, self::DELETED_REASON)) {
                        $cancelled[] = $request->id;
                    }
                });

            ChatIdentity::where('user_id', $user->id)->delete();

            $this->endSessions($user);

            audit()->record('user.deleted', metadata: [
                'user' => $user->email,
                'cancelled_requests' => $cancelled,
            ]);

            $user->delete();
        }, attempts: 3);
    }

    /** Drop live sessions and invalidate remember-me cookies. */
    private function endSessions(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
