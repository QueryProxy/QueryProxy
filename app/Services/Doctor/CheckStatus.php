<?php

namespace App\Services\Doctor;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    /** The check does not apply to this installation (nothing configured, driver without the feature). */
    case Skip = 'skip';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
