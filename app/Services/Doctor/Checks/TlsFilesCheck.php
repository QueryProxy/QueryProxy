<?php

namespace App\Services\Doctor\Checks;

use App\Models\Connection;
use App\Services\Connections\TlsPemStore;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;
use RuntimeException;

/**
 * The TLS PEM material (Phase 04): stored PEMs still decrypt, the files
 * TlsPemStore wrote are private (dir 0700, files 0600), and no directory is
 * left behind for a connection that no longer exists. Files are written lazily
 * when a connection is used, so a missing file is not a problem.
 */
class TlsFilesCheck extends BaseCheck implements NeedsDatabase
{
    public function name(): string
    {
        return 'tls_files';
    }

    public function run(): CheckResult
    {
        $store = app(TlsPemStore::class);
        $stored = 0;
        $undecryptable = [];
        $loose = [];
        $ids = [];

        Connection::query()->orderBy('id')->each(function (Connection $connection) use ($store, &$stored, &$undecryptable, &$loose, &$ids) {
            $ids[] = (string) $connection->getKey();

            foreach (Connection::TLS_PEM_KINDS as $kind) {
                if (! $connection->hasTlsPem($kind)) {
                    continue;
                }

                $stored++;

                try {
                    $connection->tlsPem($kind);
                } catch (RuntimeException) {
                    $undecryptable[] = "{$connection->name} ({$kind})";
                }
            }

            $directory = $store->directory($connection);

            if (is_dir($directory)) {
                array_push($loose, ...$this->loosePermissions($directory));
            }
        });

        $orphans = $this->orphanDirectories($ids);

        if ($undecryptable !== []) {
            return $this->fail('Stored TLS PEM cannot be decrypted (the application key changed?): '.$this->nameList($undecryptable).'. Replace or remove the value.');
        }

        $problems = [];

        if ($loose !== []) {
            $problems[] = 'readable beyond the owner: '.$this->nameList($loose);
        }

        if ($orphans !== []) {
            $problems[] = 'files for deleted connections: '.$this->nameList($orphans);
        }

        if ($problems !== []) {
            return $this->warn('TLS files under storage/app/tls — '.implode('; ', $problems).'.');
        }

        return $stored === 0
            ? $this->ok('No TLS PEM material stored.')
            : $this->ok("{$stored} stored PEM value(s) decrypt; files are private.");
    }

    /** @return list<string> */
    private function loosePermissions(string $directory): array
    {
        $loose = [];

        foreach ([$directory, ...glob($directory.'/*') ?: []] as $path) {
            $mode = @fileperms($path);

            if ($mode !== false && ($mode & 0077) !== 0) {
                $loose[] = $this->relative($path).' ('.substr(sprintf('%o', $mode), -4).')';
            }
        }

        return $loose;
    }

    /**
     * @param  list<string>  $connectionIds
     * @return list<string>
     */
    private function orphanDirectories(array $connectionIds): array
    {
        $root = storage_path('app/tls');

        if (! is_dir($root)) {
            return [];
        }

        $orphans = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $connectionIds, true)) {
                continue;
            }

            $orphans[] = $this->relative($root.'/'.$entry);
        }

        return $orphans;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(storage_path(), '', $path), '/');
    }
}
