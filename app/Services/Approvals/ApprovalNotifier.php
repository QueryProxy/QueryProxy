<?php

namespace App\Services\Approvals;

use App\Enums\TeamRole;
use App\Models\QueryRequest;
use App\Notifications\QueryRequestDecided;
use App\Notifications\QueryRequestFinished;
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
            ->get();

        Notification::send($dbas, new QueryRequestSubmitted($request));

        if (class_exists(ChatNotifier::class)) {
            app(ChatNotifier::class)->requestSubmitted($request);
        }
    }

    public static function requestDecided(QueryRequest $request): void
    {
        $request->requester->notify(new QueryRequestDecided($request));

        if (class_exists(ChatNotifier::class)) {
            app(ChatNotifier::class)->requestDecided($request);
        }
    }

    public static function requestFinished(QueryRequest $request): void
    {
        $request->requester->notify(new QueryRequestFinished($request));
    }
}
