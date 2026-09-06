<?php

namespace App\Enums;

enum MaskMatchType: string
{
    case Column = 'column';
    case Regex = 'regex';

    public function label(): string
    {
        return match ($this) {
            self::Column => 'Column name pattern',
            self::Regex => 'Content regex',
        };
    }
}
