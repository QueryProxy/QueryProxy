<?php

namespace App\Notifications;

use App\Enums\MailEvent;
use App\Models\QueryRequest;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QueryRequestSubmitted extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public QueryRequest $request) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::RequestSubmitted;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request;

        $message = $this->mailMessage(sprintf('Request #%d is waiting for review', $request->id))
            ->line(sprintf(
                '%s submitted a %s request (#%d) on %s.',
                $this->mailText($request->requester->name),
                $request->type->value,
                $request->id,
                $this->mailText($request->connection->name),
            ));

        if (($sql = $this->redactedSql($request)) !== null) {
            $message->line('The statement, with its values hidden: '.$this->mailText($sql));
        }

        return $this->mailClosing($message->action('Review the request', route('requests.show', $request)));
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
