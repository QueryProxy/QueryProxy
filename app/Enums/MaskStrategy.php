<?php

namespace App\Enums;

enum MaskStrategy: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Hash = 'hash';
    case Hmac = 'hmac';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full (*****)',
            self::Partial => 'Partial (keep edges)',
            self::Hash => 'Hash (sha256 prefix) - deprecated',
            self::Hmac => 'HMAC (keyed hash, per-team key)',
        };
    }

    /**
     * The plain `hash` strategy is unkeyed: anyone who can guess the input
     * space (phone numbers, national ids) can reverse it with a lookup table.
     * It stays selectable for existing rules; `hmac` replaces it.
     */
    public function isDeprecated(): bool
    {
        return $this === self::Hash;
    }
}
