<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Services\Doctor\SchedulerHeartbeat;

/** The scheduler (result pruning, audit checkpoints, reminders) beats once a minute. */
class SchedulerCheck extends BaseCheck
{
    public function name(): string
    {
        return 'scheduler';
    }

    public function run(): CheckResult
    {
        if ((string) config('cache.default') === 'array') {
            return $this->skip('The array cache cannot carry a heartbeat between processes.');
        }

        $age = SchedulerHeartbeat::ageInSeconds();

        if ($age === null) {
            return $this->warn('No scheduler heartbeat recorded; start the scheduler (php artisan schedule:work). Right after a start this is normal for up to a minute.');
        }

        if ($age > SchedulerHeartbeat::STALE_AFTER) {
            return $this->warn("The last scheduler heartbeat is {$age}s old (expected every 60s); the scheduler has stopped.");
        }

        return $this->ok("Scheduler heartbeat {$age}s ago.");
    }
}
