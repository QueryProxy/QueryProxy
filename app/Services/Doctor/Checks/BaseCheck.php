<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\Check;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\CheckStatus;
use Illuminate\Support\Env;

abstract class BaseCheck implements Check
{
    /** Most connection / team names listed in one message. */
    protected const LIST_LIMIT = 5;

    protected function ok(string $message): CheckResult
    {
        return new CheckResult($this->name(), CheckStatus::Ok, $message);
    }

    protected function warn(string $message): CheckResult
    {
        return new CheckResult($this->name(), CheckStatus::Warn, $message);
    }

    protected function fail(string $message): CheckResult
    {
        return new CheckResult($this->name(), CheckStatus::Fail, $message);
    }

    protected function skip(string $message): CheckResult
    {
        return new CheckResult($this->name(), CheckStatus::Skip, $message);
    }

    /**
     * A process environment variable that is not mapped to config (the
     * entrypoint's own switches). Empty strings count as unset.
     */
    protected function env(string $name): ?string
    {
        $value = Env::getRepository()->get($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  iterable<string>  $names
     */
    protected function nameList(iterable $names): string
    {
        $names = is_array($names) ? array_values($names) : iterator_to_array($names, false);
        $shown = array_slice($names, 0, self::LIST_LIMIT);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? " and {$more} more" : '');
    }

    /** First line of an exception message, bounded, for a one-row report. */
    protected function brief(\Throwable $e): string
    {
        $line = trim(strtok($e->getMessage(), "\n") ?: $e::class);

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 157).'...' : $line;
    }
}
