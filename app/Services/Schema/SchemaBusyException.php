<?php

namespace App\Services\Schema;

use RuntimeException;

/** Every connection slot of the target is taken, so no schema read can start now. */
class SchemaBusyException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Every database slot of this connection is busy. Try again shortly.');
    }
}
