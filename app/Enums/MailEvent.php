<?php

namespace App\Enums;

/**
 * The events a user can opt into by e-mail (users.notification_preferences,
 * PRD 3.4). Everything is off until the user turns it on.
 */
enum MailEvent: string
{
    case RequestSubmitted = 'request_submitted';
    case RequestDecided = 'request_decided';
    case RequestFinished = 'request_finished';
    case RequestReminder = 'request_reminder';
    case GrantRequested = 'grant_requested';
    case GrantDecided = 'grant_decided';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::RequestSubmitted => 'A request is waiting for my review',
            self::RequestDecided => 'My request was approved or rejected',
            self::RequestFinished => 'My request finished, failed or was stopped',
            self::RequestReminder => 'A request is still waiting for review (reminder)',
            self::GrantRequested => 'Someone asked for access to a connection I manage',
            self::GrantDecided => 'My access request was approved or rejected',
        };
    }
}
