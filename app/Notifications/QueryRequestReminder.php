<?php

namespace App\Notifications;

use App\Enums\MailEvent;
use App\Models\QueryRequest;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QueryRequestReminder extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public QueryRequest $request) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::RequestReminder;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request;

        $message = $this->mailMessage(sprintf('Reminder: request #%d is still waiting for review', $request->id))
            ->line(sprintf(
                '%s request #%d from %s on %s has been waiting for review for %s.',
                ucfirst($request->type->value),
                $request->id,
                $this->mailText($request->requester->name),
                $this->mailText($request->connection->name),
                $request->created_at->diffForHumans(syntax: true),
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
