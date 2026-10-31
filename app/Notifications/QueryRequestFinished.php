<?php

namespace App\Notifications;

use App\Enums\MailEvent;
use App\Enums\QueryRequestStatus;
use App\Models\QueryRequest;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QueryRequestFinished extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public QueryRequest $request) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::RequestFinished;
    }

    /** Neither the result nor the error text is e-mailed; both are read in the app. */
    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request;
        $completed = $request->status === QueryRequestStatus::Completed;

        $message = $this->mailMessage(sprintf('Request #%d %s', $request->id, $completed ? 'completed' : 'failed'))
            ->line($completed
                ? sprintf('Your request #%d on %s completed in %d ms.', $request->id, $this->mailText($request->connection->name), $request->duration_ms)
                : sprintf('Your request #%d on %s failed. Open the request to see the error.', $request->id, $this->mailText($request->connection->name)));

        return $this->mailClosing($message->action('Open the request', route('requests.show', $request)));
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
