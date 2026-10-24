<?php

namespace App\Notifications;

use App\Models\QueryRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a requester that somebody else stopped their running query.
 */
class QueryRequestStopped extends Notification
{
    use Queueable;

    public function __construct(public QueryRequest $request, public User $stoppedBy) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'query_request_id' => $this->request->id,
            'kind' => 'stopped',
            'message' => sprintf('Request #%d was stopped by %s while it was running.', $this->request->id, $this->stoppedBy->name),
        ];
    }
}
