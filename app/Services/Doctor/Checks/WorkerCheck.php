<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Worker liveness, read from the queue: a worker leaves no heartbeat, but jobs
 * that nobody has picked up for longer than one full query run mean nobody is
 * consuming. Measurable on the database queue only.
 */
class WorkerCheck extends BaseCheck implements NeedsDatabase
{
    /** Seconds on top of the execution timeout before a waiting job is suspicious. */
    public const GRACE = 60;

    public function name(): string
    {
        return 'worker';
    }

    public function run(): CheckResult
    {
        $queue = (string) config('queue.default');

        if ($queue !== 'database') {
            return $this->skip("Worker liveness is only measured on the database queue (QUEUE_CONNECTION={$queue}).");
        }

        $connection = config('queue.connections.database.connection');
        $table = (string) config('queue.connections.database.table', 'jobs');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            return $this->fail("The queue table \"{$table}\" does not exist; run: php artisan migrate --force");
        }

        $threshold = (int) config('queryproxy.execution_timeout') + self::GRACE;

        $stale = DB::connection($connection)->table($table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subSeconds($threshold)->getTimestamp())
            ->count();

        if ($stale > 0) {
            return $this->warn("{$stale} job(s) have waited more than {$threshold}s without being picked up; no queue worker seems to be running (php artisan queue:work --queue=queries,default).");
        }

        return $this->ok("No job has waited more than {$threshold}s for a worker.");
    }
}
