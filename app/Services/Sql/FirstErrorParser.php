<?php

namespace App\Services\Sql;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;

/**
 * A parser that keeps only the first error it reports.
 *
 * The guard reads only whether a statement parsed cleanly. The stock parser
 * stores an exception, with its stack trace, for every error, and on a
 * maximum-size statement it stumbles over token after token: hundreds of
 * megabytes. The first error already answers the question.
 */
final class FirstErrorParser extends Parser
{
    public function error(string $msg, ?Token $token = null, int $code = 0): void
    {
        if ($this->errors === []) {
            parent::error($msg, $token, $code);
        }
    }
}
