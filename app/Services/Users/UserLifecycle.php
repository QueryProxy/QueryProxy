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
     */
    public function suspend(User $user): void
    {
        if ($user->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['suspended_at' => now()])->save();

            $this->endSessions($user);

            audit()->record('user.suspended', metadata: ['user' => $user->email]);
        });
    }

    public function unsuspend(User $user): void
    {
        if (! $user->isSuspended()) {
            return;
        }

        $user->forceFill(['suspended_at' => null])->save();

        audit()->record('user.unsuspended', metadata: ['user' => $user->email]);
    }

    /**
     * Soft delete the user and cancel their pending requests (only those;
     * approved and queued requests keep running) in one transaction: if any
     * cancellation fails the delete rolls back, so a deleted user never keeps a
     * pending request.
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
        });
    }

    /** Drop live sessions and invalidate remember-me cookies. */
    private function endSessions(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
