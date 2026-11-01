<?php

namespace App\Enums;

/**
 * What a connection grant allows (ADR-025). `write` is the pre-0.4.0
 * behaviour: any statement the SQL guard lets through. `read` refuses a
 * request the guard classifies as a write or DDL.
 */
enum GrantAccessLevel: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function allowsWrite(): bool
    {
        return $this === self::Write;
    }
}
