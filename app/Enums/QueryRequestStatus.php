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

    /** Tailwind badge classes per status. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-700',
            self::Approved, self::Queued => 'bg-sky-100 text-sky-700',
            self::Running => 'bg-indigo-100 text-indigo-700',
            self::Completed => 'bg-emerald-100 text-emerald-700',
            self::Rejected, self::Failed => 'bg-rose-100 text-rose-700',
            self::Cancelled => 'bg-slate-100 text-slate-500',
        };
    }
}
