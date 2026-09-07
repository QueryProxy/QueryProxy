<?php

namespace App\Policies;

use App\Models\QueryRequest;
use App\Models\User;

/**
 * Unlike the other policies, this one is asked about system admins rather than
 * bypassed for them (see AppServiceProvider): its rules are two different kinds
 * of thing, and only one of them is an admin's to override.
 *
 * - Permission — who may act: team membership, the DBA role, the self-review block.
 *   An admin overrides all of it, and ApprovalService records that as an override.
 * - State — whether the request can still be acted on at all. That is the request
 *   state machine, and nobody overrides it: a decided request must never be
 *   decidable again, or approving it a second time re-dispatches the execution job
 *   and rewrites the approval trail.
 */
class QueryRequestPolicy
{
    public function view(User $user, QueryRequest $request): bool
    {
        if ($user->isAdmin() || $request->user_id === $user->id) {
            return true;
        }

        $team = $request->team;

        return $user->isDbaIn($team) || $user->isAuditorIn($team);
    }

    /** Approve or reject. */
    public function review(User $user, QueryRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        // Admins override both the DBA-membership rule and the self-review block.
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isDbaIn($request->team) && $request->user_id !== $user->id;
    }

    public function cancel(User $user, QueryRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        return $user->isAdmin() || $request->user_id === $user->id;
    }

    public function downloadResult(User $user, QueryRequest $request): bool
    {
        return $request->hasResult() && $this->view($user, $request);
    }
}
