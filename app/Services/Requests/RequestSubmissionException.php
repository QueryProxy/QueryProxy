<?php

namespace App\Services\Requests;

use RuntimeException;

/**
 * A submission the SQL guard refused. Carries the violations so each channel
 * (web form, REST API) can show them in its own way.
 */
class RequestSubmissionException extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct($violations === [] ? 'The query was rejected by the SQL guard.' : implode("\n", $violations));
    }
}
