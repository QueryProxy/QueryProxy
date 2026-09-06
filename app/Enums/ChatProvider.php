<?php

namespace App\Enums;

enum ChatProvider: string
{
    case Slack = 'slack';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
        };
    }
}
