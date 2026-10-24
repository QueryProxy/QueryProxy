<?php

namespace App\Services\Approvals;

use App\Enums\QueryRequestStatus;
use App\Jobs\ExecuteQueryRequest;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Execution\BackendCanceller;
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
     * Stop a request that is running: move it to cancelled, then ask the
     * target database to cancel the statement from a second connection.
     *
     * The state moves first, in one conditional update that also requires the
     * recorded session id to be unchanged: whichever of this claim and the
     * run's own completion lands first wins, so the run can never record a
     * failure for the error the cancellation causes, and a session id that
     * outlived its run is never signalled. The executor then sees a request
     * that is no longer running and records nothing more (a run that still
     * finished is logged as execution.late_completion).
     *
     * The signal names the session by id and start stamp (BackendCanceller), so
     * an id the server has since given to another session is left alone.
     * The requester is notified when somebody else stopped the run.
     *
     * Not delivering the signal does not undo the stop: the statement then ends
     * on its own, at the latest at the database-side statement timeout.
     *
     * @return bool whether the database accepted the cancel signal
     *
     * @throws AuthorizationException when the user may not stop it, or it stopped running meanwhile
     */
    public function stop(QueryRequest $request, User $user): bool
    {
        Gate::forUser($user)->authorize('stop', $request);

        $pid = (int) $request->backend_pid;
        $token = $request->backend_session;

        $claimed = QueryRequest::whereKey($request->id)
            ->where('status', QueryRequestStatus::Running)
            ->where('backend_pid', $pid)
            ->when($token === null, fn ($query) => $query->whereNull('backend_session'), fn ($query) => $query->where('backend_session', $token))
            ->update([
                'status' => QueryRequestStatus::Cancelled,
                'backend_pid' => null,
                'backend_session' => null,
            ]);

        if ($claimed === 0) {
            throw new AuthorizationException('This request is no longer running.');
        }

        $request->refresh();

        $signalled = app(BackendCanceller::class)->cancel($request->connection, $pid, $token, $request->runsAsReader());

        audit()->record('execution.cancelled', actor: $user, request: $request, metadata: array_filter([
            'signalled' => $signalled,
            'duration_ms' => $request->executed_at ? (int) $request->executed_at->diffInMilliseconds(now(), true) : null,
        ], fn ($value) => $value !== null));

        ApprovalNotifier::requestStopped($request, $user);

        return $signalled;
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
