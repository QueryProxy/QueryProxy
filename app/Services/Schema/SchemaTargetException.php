<?php

namespace App\Services\Schema;

use RuntimeException;
use Throwable;

/**
 * The target database (or opening it) failed. Its message may carry the
 * target's own coordinates and values, so the caller shows it only after
 * ErrorMessageSanitizer; any other failure of a read is the application's own
 * and is never put into an answer.
 */
class SchemaTargetException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
