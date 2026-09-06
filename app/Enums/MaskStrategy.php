<?php

namespace App\Enums;

enum MaskStrategy: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Hash = 'hash';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full (*****)',
            self::Partial => 'Partial (keep edges)',
            self::Hash => 'Hash (sha256 prefix)',
        };
    }
}
