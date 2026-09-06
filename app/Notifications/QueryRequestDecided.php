<?php

namespace App\Notifications;

use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QueryRequestDecided extends Notification
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
        $approved = $this->request->status !== QueryRequestStatus::Rejected;

        return [
            'query_request_id' => $this->request->id,
            'kind' => $approved ? 'approved' : 'rejected',
            'message' => sprintf(
                'Request #%d was %s by %s%s',
                $this->request->id,
                $approved ? 'approved' : 'rejected',
                $this->request->reviewer?->name ?? 'a reviewer',
                $approved ? '' : ': '.$this->request->rejection_reason,
            ),
        ];
    }
}
