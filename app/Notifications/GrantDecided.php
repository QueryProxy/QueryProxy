<?php

namespace App\Notifications;

use App\Enums\GrantRequestStatus;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** To the requester: a DBA approved or rejected the access request (ADR-025). */
class GrantDecided extends Notification
{
    use Queueable;

    /** @param ConnectionGrant|null $grant the grant in force after an approval */
    public function __construct(public ConnectionGrantRequest $grantRequest, public ?ConnectionGrant $grant = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $approved = $this->grantRequest->status === GrantRequestStatus::Approved;

        return [
            'kind' => $approved ? 'grant_approved' : 'grant_rejected',
            'grant_request_id' => $this->grantRequest->id,
            'connection_id' => $this->grantRequest->connection_id,
            'message' => sprintf(
                'Your %s access request for %s was %s by %s%s',
                $this->grantRequest->access_level->value,
                $this->grantRequest->connection->name,
                $approved ? 'approved' : 'rejected',
                $this->grantRequest->decider?->name ?? 'a reviewer',
                $approved
                    ? ($this->grant !== null ? '; now in force: '.$this->grant->describe() : sprintf(' for %s', $this->grantRequest->durationLabel()))
                    : ($this->grantRequest->decision_note ? ': '.$this->grantRequest->decision_note : ''),
            ),
        ];
    }
}
