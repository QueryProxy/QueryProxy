<?php

namespace App\Notifications;

use App\Enums\MailEvent;
use App\Models\QueryRequest;
use App\Models\User;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a requester that somebody else stopped their running query.
 */
class QueryRequestStopped extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public QueryRequest $request, public User $stoppedBy) {}

    /** Stopped is the end of a running request, so it follows the "finished" choice. */
    protected function mailEvent(): MailEvent
    {
        return MailEvent::RequestFinished;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request;

        $message = $this->mailMessage(sprintf('Request #%d was stopped', $request->id))
            ->line(sprintf(
                'Your request #%d on %s was stopped by %s while it was running.',
                $request->id,
                $this->mailText($request->connection->name),
                $this->mailText($this->stoppedBy->name),
            ));

        return $this->mailClosing($message->action('Open the request', route('requests.show', $request)));
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
