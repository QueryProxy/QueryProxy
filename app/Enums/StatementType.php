<?php

namespace App\Enums;

enum StatementType: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return $this->value;
    }

    /** Semantic tone consumed by <x-ui.badge>; the palette itself lives in app.css. */
    public function tone(): string
    {
        return $this->value;
    }
}
