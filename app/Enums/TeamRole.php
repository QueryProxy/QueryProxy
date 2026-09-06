<?php

namespace App\Enums;

enum TeamRole: string
{
    case Dba = 'dba';
    case Developer = 'developer';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Dba => 'DBA',
            self::Developer => 'Developer',
            self::Auditor => 'Auditor',
        };
    }
}
