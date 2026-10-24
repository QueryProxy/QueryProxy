<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/** The directories the application writes to are writable by this process. */
class StoragePermissionsCheck extends BaseCheck
{
    public function name(): string
    {
        return 'storage_permissions';
    }

    public function run(): CheckResult
    {
        $broken = [];

        foreach ($this->paths() as $path) {
            if (! $this->writable($path)) {
                $broken[] = $path;
            }
        }

        if ($broken !== []) {
            return $this->fail('Not writable by the current user: '.implode(', ', $broken).'.');
        }

        return $this->ok(count($this->paths()).' writable paths checked.');
    }

    /** @return list<string> */
    protected function paths(): array
    {
        $paths = [
            storage_path('app'),
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
        ];

        $default = config('database.default');

        if (config("database.connections.{$default}.driver") === 'sqlite') {
            $database = (string) config("database.connections.{$default}.database");

            if ($database !== '' && $database !== ':memory:') {
                $paths[] = $database;
                // SQLite creates its journal next to the file.
                $paths[] = dirname($database);
            }
        }

        return array_values(array_unique($paths));
    }

    /** A path that does not exist yet counts as writable when the nearest existing parent is. */
    private function writable(string $path): bool
    {
        while (! file_exists($path)) {
            $parent = dirname($path);

            if ($parent === $path) {
                return false;
            }

            $path = $parent;
        }

        return is_writable($path);
    }
}
