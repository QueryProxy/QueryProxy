<?php

namespace App\Services\Approvals;

use App\Enums\TeamRole;
use App\Models\QueryRequest;
use App\Models\User;
use App\Notifications\QueryRequestDecided;
use App\Notifications\QueryRequestFinished;
use App\Notifications\QueryRequestReminder;
use App\Notifications\QueryRequestStopped;
use App\Notifications\QueryRequestSubmitted;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Notification;

/**
 * Fans out request lifecycle notifications. Web (database) channel now;
 * chat channels (Slack / Teams) hook in via ChatNotifier in Phase 8.
 */
class ApprovalNotifier
{
    public static function requestSubmitted(QueryRequest $request): void
    {
        $dbas = $request->team->users()
            ->wherePivot('role', TeamRole::Dba->value)
            ->where('users.id', '!=', $request->user_id)
            ->whereNull('users.suspended_at')
            ->get();

        Notification::send($dbas, new QueryRequestSubmitted($request));

        if (class_exists(ChatNotifier::class)) {
            app(ChatNotifier::class)->requestSubmitted($request);
        }
    }

    /**
     * The one-time "still pending" nudge: the team's DBAs and any admin who is
     * a member of the team, in-app and on the chat channels.
     */
    public static function requestReminder(QueryRequest $request): void
    {
        $reviewers = $request->team->users()
            ->where(fn ($query) => $query
                ->where('team_user.role', TeamRole::Dba->value)
                ->orWhere('users.is_admin', true))
            ->where('users.id', '!=', $request->user_id)
            ->get();

        Notification::send($reviewers, new QueryRequestReminder($request));

        app(ChatNotifier::class)->requestReminder($request);
    }

    /**
     * Chat only: the buttons of the earlier message expired while the request
     * is still pending, so the channels get the card again with new tokens.
     */
    public static function chatCardRenewed(QueryRequest $request): void
    {
        app(ChatNotifier::class)->requestCardRenewed($request);
    }

    public static function requestDecided(QueryRequest $request): void
    {
        // A deleted requester (their request was approved before the delete) has no inbox left.
        if (! $request->requester->trashed()) {
            $request->requester->notify(new QueryRequestDecided($request));
        }

        if (class_exists(ChatNotifier::class)) {
            app(ChatNotifier::class)->requestDecided($request);
        }
    }

    public static function requestFinished(QueryRequest $request): void
    {
        if (! $request->requester->trashed()) {
            $request->requester->notify(new QueryRequestFinished($request));
        }
    }

    /**
     * Somebody other than the requester stopped the running query: the
     * requester hears about it in-app. Stopping your own query tells you
     * nothing you do not know.
     */
    public static function requestStopped(QueryRequest $request, User $stoppedBy): void
    {
        if ($request->user_id === $stoppedBy->id || $request->requester->trashed()) {
            return;
        }

        $request->requester->notify(new QueryRequestStopped($request, $stoppedBy));
    }
}
