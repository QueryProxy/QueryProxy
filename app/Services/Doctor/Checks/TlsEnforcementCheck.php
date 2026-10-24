<?php

namespace App\Services\Doctor\Checks;

use App\Models\Connection;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;

/**
 * Connections whose TLS is not required (ADR-019): PostgreSQL left on
 * `prefer` or `disable`, and MySQL/MariaDB/SQL Server left to the driver
 * default. Reads the settings Phase 04 stores in `options.tls`.
 */
class TlsEnforcementCheck extends BaseCheck implements NeedsDatabase
{
    public function name(): string
    {
        return 'tls_enforcement';
    }

    public function run(): CheckResult
    {
        $notEnforced = [];
        $driverDefault = [];
        $network = 0;

        Connection::query()->orderBy('id')->each(function (Connection $connection) use (&$notEnforced, &$driverDefault, &$network) {
            match ($connection->tlsState()) {
                Connection::TLS_NOT_ENFORCED => $notEnforced[] = $this->label($connection),
                Connection::TLS_DRIVER_DEFAULT => $driverDefault[] = $this->label($connection),
                Connection::TLS_ENFORCED => $network++,
                default => null,
            };
        });

        $network += count($notEnforced) + count($driverDefault);

        if ($network === 0) {
            return $this->skip('No connection uses a network transport.');
        }

        if ($notEnforced === [] && $driverDefault === []) {
            return $this->ok("TLS is required on all {$network} network connection(s).");
        }

        $parts = [];

        if ($notEnforced !== []) {
            $parts[] = count($notEnforced).' not enforcing TLS, plaintext fallback possible ('.$this->nameList($notEnforced).')';
        }

        if ($driverDefault !== []) {
            $parts[] = count($driverDefault).' left to the driver default ('.$this->nameList($driverDefault).')';
        }

        return $this->warn(implode('; ', $parts).'. Edit the connection and require TLS.');
    }

    private function label(Connection $connection): string
    {
        return $connection->name.' ['.($connection->tlsMode() ?? 'default').']';
    }
}
