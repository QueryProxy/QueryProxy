<?php

namespace App\Services\Approvals;

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The single entry point for approval decisions, used by the web UI and the
 * chat (Slack / Teams) callbacks alike, so every channel enforces identical rules.
 */
class ApprovalService
{
    /**
     * @throws AuthorizationException
     */
    public function approve(QueryRequest $request, User $reviewer, string $channel = 'web'): void
    {
        Gate::forUser($reviewer)->authorize('review', $request);

        $isOverride = ! $reviewer->isDbaIn($request->team) || $request->user_id === $reviewer->id;

        // Conditional update so two concurrent approvals (e.g. web + a stale
        // Slack button) can never both claim the request and dispatch it twice.
        $claimed = QueryRequest::whereKey($request->id)
            ->where('status', QueryRequestStatus::Pending)
            ->update([
                'status' => QueryRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_channel' => $channel,
            ]);

        if ($claimed === 0) {
            throw new AuthorizationException('This request has already been decided.');
        }

        $request->refresh();

        audit()->record('request.approved', actor: $reviewer, request: $request, metadata: [
            'channel' => $channel,
            'override' => $isOverride,
        ]);

        ApprovalNotifier::requestDecided($request);

        $request->update(['status' => QueryRequestStatus::Queued]);

        ExecuteQueryRequest::dispatch($request);
    }

    /**
     * @throws AuthorizationException
     */
    public function reject(QueryRequest $request, User $reviewer, string $reason, string $channel = 'web'): void
    {
        Gate::forUser($reviewer)->authorize('review', $request);

        $claimed = QueryRequest::whereKey($request->id)
            ->where('status', QueryRequestStatus::Pending)
            ->update([
                'status' => QueryRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'review_channel' => $channel,
            ]);

        if ($claimed === 0) {
            throw new AuthorizationException('This request has already been decided.');
        }

        $request->refresh();

        audit()->record('request.rejected', actor: $reviewer, request: $request, metadata: [
            'channel' => $channel,
            'reason' => $reason,
        ]);

        ApprovalNotifier::requestDecided($request);
    }

    public function cancel(QueryRequest $request, User $user): void
    {
        Gate::forUser($user)->authorize('cancel', $request);

        $claimed = QueryRequest::whereKey($request->id)
            ->where('status', QueryRequestStatus::Pending)
            ->update(['status' => QueryRequestStatus::Cancelled]);

        if ($claimed === 0) {
            throw new AuthorizationException('This request has already been decided.');
        }

        $request->refresh();

        audit()->record('request.cancelled', actor: $user, request: $request);
    }
}
