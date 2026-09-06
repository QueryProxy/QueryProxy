<?php

namespace App\Notifications;

use App\Models\QueryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QueryRequestSubmitted extends Notification
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
            'kind' => 'submitted',
            'message' => sprintf(
                '%s submitted %s request #%d on %s',
                $this->request->requester->name,
                $this->request->type->value,
                $this->request->id,
                $this->request->connection->name,
            ),
        ];
    }
}
