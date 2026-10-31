<?php

namespace App\Notifications;

use App\Enums\MailEvent;
use App\Models\ConnectionGrantRequest;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team's DBAs: a developer asked for time-bound access (ADR-025). */
class GrantRequested extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    public function __construct(public ConnectionGrantRequest $grantRequest) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::GrantRequested;
    }

    /** The requester's reason is left out; the DBA reads it in the app. */
    public function toMail(object $notifiable): MailMessage
    {
        $grantRequest = $this->grantRequest;

        $message = $this->mailMessage('Access request waiting for your decision')
            ->line(sprintf(
                '%s asked for %s access to %s for %s.',
                $this->mailText($grantRequest->requester->name),
                $grantRequest->access_level->value,
                $this->mailText($grantRequest->connection->name),
                $grantRequest->durationLabel(),
            ));

        return $this->mailClosing($message->action('Review on Connections', route('connections.index')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'grant_requested',
            'grant_request_id' => $this->grantRequest->id,
            'connection_id' => $this->grantRequest->connection_id,
            'message' => sprintf(
                '%s asked for %s access to %s for %s',
                $this->grantRequest->requester->name,
                $this->grantRequest->access_level->value,
                $this->grantRequest->connection->name,
                $this->grantRequest->durationLabel(),
            ),
        ];
    }
}
