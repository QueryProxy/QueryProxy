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

class QueryRequestDecided extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public QueryRequest $request) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::RequestDecided;
    }

    /** The reviewer's note is left out of the e-mail; it is read in the app. */
    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request;
        $approved = $request->status !== QueryRequestStatus::Rejected;

        $message = $this->mailMessage(sprintf('Request #%d was %s', $request->id, $approved ? 'approved' : 'rejected'))
            ->line(sprintf(
                'Your request #%d on %s was %s by %s.',
                $request->id,
                $this->mailText($request->connection->name),
                $approved ? 'approved' : 'rejected',
                $this->mailText($request->reviewer?->name ?? 'a reviewer'),
            ));

        if (! $approved) {
            $message->line('Open the request to read the reviewer\'s reason.');
        }

        return $this->mailClosing($message->action('Open the request', route('requests.show', $request)));
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
