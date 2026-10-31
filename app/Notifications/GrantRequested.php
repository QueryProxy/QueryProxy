<?php

namespace App\Notifications;

use App\Models\ConnectionGrantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** To the team's DBAs: a developer asked for time-bound access (ADR-025). */
class GrantRequested extends Notification
{
    use Queueable;

    public function __construct(public ConnectionGrantRequest $grantRequest) {}

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
