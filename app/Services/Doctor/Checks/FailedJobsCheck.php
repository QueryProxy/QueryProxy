<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FailedJobsCheck extends BaseCheck implements NeedsDatabase
{
    public function name(): string
    {
        return 'failed_jobs';
    }

    public function run(): CheckResult
    {
        $table = config('queue.failed.table');
        $connection = config('queue.failed.database');

        if (! is_string($table) || $table === '' || ! Schema::connection($connection)->hasTable($table)) {
            return $this->skip('Failed jobs are not stored in a database table.');
        }

        $count = DB::connection($connection)->table($table)->count();

        return $count === 0
            ? $this->ok('No failed jobs.')
            : $this->warn("{$count} failed job(s); inspect with: php artisan queue:failed (SIEM deliveries and query runs end up here).");
    }
}
