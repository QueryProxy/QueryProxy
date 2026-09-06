<?php

namespace App\Notifications;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QueryRequestFinished extends Notification
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
        $completed = $this->request->status === QueryRequestStatus::Completed;

        return [
            'query_request_id' => $this->request->id,
            'kind' => $completed ? 'completed' : 'failed',
            'message' => $completed
                ? sprintf('Request #%d completed in %d ms.', $this->request->id, $this->request->duration_ms)
                : sprintf('Request #%d failed: %s', $this->request->id, str($this->request->error_message)->limit(120)),
        ];
    }
}
