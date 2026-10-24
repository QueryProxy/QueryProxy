<?php

namespace App\Services\Connections;

use App\Models\Connection;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;

/**
 * Materialises a connection's encrypted TLS PEM material (CA, client
 * certificate, client key) as files under storage/app/tls/<connection-id>/.
 *
 * The drivers (libpq, mysqlnd) only accept file paths. Directories are 0700,
 * files 0600, and everything is removed when the connection is deleted.
 */
class TlsPemStore
{
    public function directory(Connection $connection): string
    {
        if (! $connection->exists || $connection->getKey() === null) {
            throw new LogicException('TLS PEM files can only be written for a stored connection.');
        }

        return storage_path('app/tls/'.$connection->getKey());
    }

    /**
     * Write the PEM files the connection has and delete the ones it no longer
     * has. Returns the absolute path per kind (only kinds that exist).
     *
     * @return array<string, string> kind (ca|cert|key) => file path
     */
    public function sync(Connection $connection): array
    {
        $pems = [];

        foreach (Connection::TLS_PEM_KINDS as $kind) {
            $pem = $connection->tlsPem($kind);

            if ($pem !== null) {
                $pems[$kind] = $pem;
            }
        }

        if ($pems === []) {
            $this->purge($connection);

            return [];
        }

        $directory = $this->directory($connection);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the TLS directory for the connection.');
        }

        chmod($directory, 0700);

        $paths = [];

        foreach (Connection::TLS_PEM_KINDS as $kind) {
            $path = $directory.DIRECTORY_SEPARATOR.$kind.'.pem';

            if (isset($pems[$kind])) {
                $this->writeSecret($path, $pems[$kind]);
                $paths[$kind] = $path;
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        return $paths;
    }

    /**
     * Drop the files the saved connection no longer needs, without decrypting
     * anything: every file when the connection does not use PEM files at all
     * (SQL Server, SQLite, MySQL/MariaDB left to the driver default), and the
     * files of kinds that are no longer stored otherwise. Files that are still
     * needed are (re)written lazily by sync() when the connection is used.
     */
    public function reconcile(Connection $connection): void
    {
        if (! $connection->usesTlsPemFiles()) {
            $this->purge($connection);

            return;
        }

        foreach (Connection::TLS_PEM_KINDS as $kind) {
            if (! $connection->hasTlsPem($kind)) {
                $this->removeFile($this->directory($connection).DIRECTORY_SEPARATOR.$kind.'.pem');
            }
        }

        $this->removeDirectoryIfEmpty($this->directory($connection));
    }

    /** Remove every file (and the directory) of the connection. Never throws. */
    public function purge(Connection $connection): void
    {
        if ($connection->getKey() === null) {
            return;
        }

        $this->purgeById((int) $connection->getKey());
    }

    /** Same as purge() for a connection id whose row is gone or never loaded. */
    public function purgeById(int $connectionId): void
    {
        $directory = storage_path('app/tls/'.$connectionId);

        if (! is_dir($directory)) {
            return;
        }

        // scandir (unlike glob('*')) also lists dot-files, such as a temporary
        // file left behind by a worker killed between tempnam and rename.
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeFile($directory.DIRECTORY_SEPARATOR.$entry);
            }
        }

        $this->removeDirectoryIfEmpty($directory);

        if (is_dir($directory)) {
            Log::warning('Could not fully remove the TLS directory of a connection.', ['connection_id' => $connectionId]);
        }
    }

    private function removeFile(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    private function removeDirectoryIfEmpty(string $directory): void
    {
        if (is_dir($directory) && count(scandir($directory) ?: []) <= 2) {
            @rmdir($directory);
        }
    }

    /** Create a file readable by the owner only, replacing any previous one atomically. */
    private function writeSecret(string $path, string $contents): void
    {
        $temp = tempnam(dirname($path), '.pem');

        if ($temp === false) {
            throw new RuntimeException('Could not create a temporary TLS file.');
        }

        chmod($temp, 0600);

        if (file_put_contents($temp, $contents) === false) {
            unlink($temp);

            throw new RuntimeException('Could not write the TLS file.');
        }

        if (! rename($temp, $path)) {
            unlink($temp);

            throw new RuntimeException('Could not move the TLS file into place.');
        }

        chmod($path, 0600);
    }
}
