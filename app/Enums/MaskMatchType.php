<?php

namespace App\Enums;

enum MaskMatchType: string
{
    case Column = 'column';
    case Regex = 'regex';
    case Validator = 'validator';

    public function label(): string
    {
        return match ($this) {
            self::Column => 'Column name pattern',
            self::Regex => 'Content regex',
            self::Validator => 'Content validator (checksum)',
        };
    }
}
