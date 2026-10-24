<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/**
 * Result files must leave only through the policy-checked download route, so
 * the disk behind QUERYPROXY_RESULT_DISK must not be publicly visible
 * (QueryExecutor refuses such a disk when a request runs; this tells the
 * operator before that).
 */
class ResultDiskCheck extends BaseCheck
{
    public function name(): string
    {
        return 'result_disk';
    }

    public function run(): CheckResult
    {
        $disk = (string) config('queryproxy.result_disk', 'local');
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return $this->fail("QUERYPROXY_RESULT_DISK=\"{$disk}\" is not a configured filesystem disk.");
        }

        if ($disk === 'public' || ($config['visibility'] ?? null) === 'public') {
            return $this->fail("The result disk \"{$disk}\" is publicly visible; query results would be reachable without the request policy. Use a private disk such as \"local\".");
        }

        return $this->ok("Result disk \"{$disk}\" is private.");
    }
}
