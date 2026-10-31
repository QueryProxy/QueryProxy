<?php

namespace App\Enums;

enum GrantRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Semantic tone consumed by <x-ui.badge>; the palette itself lives in app.css. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Approved => 'ok',
            self::Rejected => 'danger',
        };
    }
}
