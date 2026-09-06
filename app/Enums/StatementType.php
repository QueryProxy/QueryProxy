<?php

namespace App\Enums;

enum StatementType: string
{
    case Read = 'read';
    case Write = 'write';
}
