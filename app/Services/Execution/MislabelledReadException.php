<?php

namespace App\Services\Execution;

use RuntimeException;

/**
 * A request labelled "read" whose statement the guard classifies as a write,
 * refused before anything is sent to the target. Distinct from the database
 * refusing a write inside its read-only session: this one means the label and
 * the statement disagree (a tampered `type`, or a guard that has changed since
 * submission), which is worth telling apart in the audit trail.
 */
final class MislabelledReadException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This statement changes data, but it was submitted as a read request and read requests never change data. '.
            'Submit it as a write request if the change is intended.'
        );
    }
}
