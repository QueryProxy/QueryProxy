<?php

namespace App\Policies;

use App\Models\QueryRequest;
use App\Models\User;

class QueryRequestPolicy
{
    public function view(User $user, QueryRequest $request): bool
    {
        if ($request->user_id === $user->id) {
            return true;
        }

        $team = $request->team;

        return $user->isDbaIn($team) || $user->isAuditorIn($team);
    }

    /** Approve or reject. Self-review is forbidden; admins override via Gate::before. */
    public function review(User $user, QueryRequest $request): bool
    {
        return $request->isPending()
            && $user->isDbaIn($request->team)
            && $request->user_id !== $user->id;
    }

    public function cancel(User $user, QueryRequest $request): bool
    {
        return $request->isPending() && $request->user_id === $user->id;
    }

    public function downloadResult(User $user, QueryRequest $request): bool
    {
        return $request->hasResult() && $this->view($user, $request);
    }
}
