<?php

namespace App\Notifications;

use App\Enums\GrantRequestStatus;
use App\Enums\MailEvent;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Notifications\Concerns\DeliversByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the requester: a DBA approved or rejected the access request (ADR-025). */
class GrantDecided extends Notification implements ShouldQueue
{
    use DeliversByMail, Queueable;

    /** @param ConnectionGrant|null $grant the grant in force after an approval */
    public function __construct(public ConnectionGrantRequest $grantRequest, public ?ConnectionGrant $grant = null) {}

    protected function mailEvent(): MailEvent
    {
        return MailEvent::GrantDecided;
    }

    /** The decision note is left out of the e-mail; it is read in the app. */
    public function toMail(object $notifiable): MailMessage
    {
        $grantRequest = $this->grantRequest;
        $approved = $grantRequest->status === GrantRequestStatus::Approved;

        $message = $this->mailMessage(sprintf('Your access request was %s', $approved ? 'approved' : 'rejected'))
            ->line(sprintf(
                'Your %s access request for %s was %s by %s.',
                $grantRequest->access_level->value,
                $this->mailText($grantRequest->connection->name),
                $approved ? 'approved' : 'rejected',
                $this->mailText($grantRequest->decider?->name ?? 'a reviewer'),
            ));

        if ($approved && $this->grant !== null) {
            $message->line('Now in force: '.$this->grant->describe().'.');
        }

        return $this->mailClosing($message->action(
            $approved ? 'Open Query Studio' : 'Open the dashboard',
            $approved ? route('studio') : route('dashboard'),
        ));
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
