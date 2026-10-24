<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/**
 * The generated key file must not sit on the same volume as the SQLite
 * database: a backup or copy of that volume would carry the data and the key
 * that decrypts it (ACTION_PLAN 4.5.4; docker/entrypoint.sh).
 */
class AppKeyLocationCheck extends BaseCheck
{
    public function name(): string
    {
        return 'app_key_location';
    }

    public function run(): CheckResult
    {
        $database = $this->sqliteFile();

        if ($database === null) {
            return $this->skip('The application database is not a SQLite file.');
        }

        $key = (string) config('app.key');
        $databaseDevice = $this->deviceOf($database);

        foreach ($this->candidates() as $path) {
            if (! is_file($path) || ! is_readable($path) || trim((string) file_get_contents($path)) !== $key) {
                continue;
            }

            if ($databaseDevice !== null && $databaseDevice === $this->deviceOf($path)) {
                return $this->warn("The APP_KEY file {$path} is on the same volume as the SQLite database; a backup of that volume holds both. Supply APP_KEY (or APP_KEY_FILE) from outside the data volume.");
            }

            return $this->ok("The APP_KEY file {$path} is not on the database volume.");
        }

        return $this->ok('APP_KEY does not come from a file on the data volume.');
    }

    /** @return list<string> files that may hold the key the entrypoint generated or was given */
    protected function candidates(): array
    {
        return array_values(array_filter([
            $this->env('APP_KEY_FILE'),
            $this->env('QUERYPROXY_KEY_FILE'),
            storage_path('app/.app_key'),
            database_path('.app_key'),
        ]));
    }

    private function sqliteFile(): ?string
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return null;
        }

        $path = (string) config("database.connections.{$connection}.database");

        return $path !== '' && $path !== ':memory:' && is_file($path) ? $path : null;
    }

    /** Overridable for tests: two paths on one volume share a device id. */
    protected function deviceOf(string $path): ?int
    {
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }
}
