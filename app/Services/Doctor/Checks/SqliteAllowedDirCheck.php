<?php

namespace App\Services\Doctor\Checks;

use App\Enums\DbDriver;
use App\Models\Connection;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;

/** SQLite targets are files on this server; without a pinned directory any readable file can be added. */
class SqliteAllowedDirCheck extends BaseCheck implements NeedsDatabase
{
    public function name(): string
    {
        return 'sqlite_allowed_dir';
    }

    public function run(): CheckResult
    {
        $directory = trim((string) config('queryproxy.sqlite_allowed_dir'));
        $sqlite = Connection::query()->where('driver', DbDriver::Sqlite->value)->count();

        if ($directory === '') {
            return $sqlite > 0
                ? $this->warn("{$sqlite} SQLite connection(s) exist and QUERYPROXY_SQLITE_ALLOWED_DIR is empty; any file the server can read can be a target. Pin it to a directory.")
                : $this->ok('No SQLite connections; QUERYPROXY_SQLITE_ALLOWED_DIR is empty (set it before adding any).');
        }

        if (! is_dir($directory)) {
            return $this->warn("QUERYPROXY_SQLITE_ALLOWED_DIR=\"{$directory}\" is not an existing directory.");
        }

        return $this->ok("SQLite targets are pinned to {$directory}.");
    }
}
