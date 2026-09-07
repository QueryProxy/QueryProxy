<?php

namespace App\Enums;

enum QueryRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Failed, self::Cancelled], true);
    }

    /** Semantic tone consumed by <x-ui.badge>; the palette itself lives in app.css. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Approved, self::Queued => 'queued',
            self::Running => 'running',
            self::Completed => 'ok',
            self::Rejected, self::Failed => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}
