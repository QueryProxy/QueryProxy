<?php

namespace App\Services\Approvals;

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The single entry point for approval decisions, used by the web UI and the
 * chat (Slack / Teams) callbacks alike, so every channel enforces identical rules.
 */
class ApprovalService
{
    public const MAX_REASON_LENGTH = 1000;

    /**
     * An approval is an override when the reviewer is not a DBA of the request's
     * team (an admin stepping in) or is approving their own request.
     */
    public function isOverride(QueryRequest $request, User $reviewer): bool
    {
        return ! $reviewer->isDbaIn($request->team) || $request->user_id === $reviewer->id;
    }

    /**
     * @param  string|null  $reason  Mandatory (non-empty) for an override, on every channel.
     *
     * @throws AuthorizationException
     * @throws ValidationException when an override comes without a reason, before anything changes
     */
    public function approve(QueryRequest $request, User $reviewer, string $channel = 'web', ?string $reason = null): void
    {
        Gate::forUser($reviewer)->authorize('review', $request);

        $isOverride = $this->isOverride($request, $reviewer);
        $reason = trim((string) $reason);

        if ($isOverride) {
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'An override needs a reason.',
                ]);
            }

            if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
                throw ValidationException::withMessages([
                    'reason' => 'The reason may not be longer than '.self::MAX_REASON_LENGTH.' characters.',
                ]);
            }
        }

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
            ...($isOverride ? ['reason' => $reason] : []),
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

    /**
     * Cancel a pending request on behalf of the system (e.g. its owner was
     * deleted). No policy check: there is no acting user. The pending to
     * cancelled move is the same atomic claim as a user cancel, and the audit
     * entry carries no user — not even the signed-in one — plus metadata.actor
     * = system and the reason.
     *
     * @return bool false when the request was no longer pending (already decided)
     */
    public function cancelBySystem(QueryRequest $request, string $reason): bool
    {
        $claimed = QueryRequest::whereKey($request->id)
            ->where('status', QueryRequestStatus::Pending)
            ->update(['status' => QueryRequestStatus::Cancelled]);

        if ($claimed === 0) {
            return false;
        }

        $request->refresh();

        audit()->record('request.cancelled', request: $request, metadata: ['reason' => $reason], system: true);

        return true;
    }
}
