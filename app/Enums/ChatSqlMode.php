<?php

namespace App\Enums;

/**
 * What the chat notifications may carry of a request's SQL (ADR-017).
 * Configured by QUERYPROXY_CHAT_INCLUDE_SQL.
 */
enum ChatSqlMode: string
{
    case Full = 'full';
    case LiteralsRedacted = 'literals_redacted';
    case None = 'none';

    /**
     * The setting predates the three modes as a boolean: `true` -> full, `false` -> none.
     * Unset or unrecognised -> the safe default, literals_redacted.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value === true) {
            return self::Full;
        }

        if ($value === false) {
            return self::None;
        }

        return match (is_string($value) ? strtolower(trim($value)) : null) {
            'full', 'true', '1', 'yes', 'on' => self::Full,
            'none', 'false', '0', 'no', 'off' => self::None,
            default => self::LiteralsRedacted,
        };
    }
}
