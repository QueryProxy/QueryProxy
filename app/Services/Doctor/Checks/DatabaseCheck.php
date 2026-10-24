<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** The application database answers and has no migration waiting. */
class DatabaseCheck extends BaseCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            $connection->getPdo();
        } catch (Throwable $e) {
            return $this->fail("Cannot connect to the application database ({$driver}): ".$this->brief($e));
        }

        try {
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
            $ran = Schema::hasTable('migrations') ? $migrator->getRepository()->getRan() : [];
            $pending = array_values(array_diff($files, $ran));
        } catch (Throwable $e) {
            return $this->fail('Connected, but the migration state could not be read: '.$this->brief($e));
        }

        if ($pending !== []) {
            return $this->fail(count($pending).' migration(s) not applied (next: '.$pending[0].'); run: php artisan migrate --force');
        }

        return $this->ok("Connected ({$driver}); all ".count($files).' migrations applied.');
    }
}
