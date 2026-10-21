<?php

namespace App\Notifications;

use App\Models\QueryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QueryRequestReminder extends Notification
{
    use Queueable;

    public function __construct(public QueryRequest $request) {}

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
            'kind' => 'reminder',
            'message' => sprintf(
                'Reminder: %s request #%d from %s on %s has been waiting for review for %s',
                $this->request->type->value,
                $this->request->id,
                $this->request->requester->name,
                $this->request->connection->name,
                $this->request->created_at->diffForHumans(syntax: true),
            ),
        ];
    }
}
