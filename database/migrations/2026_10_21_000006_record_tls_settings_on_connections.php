<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Records today's effective TLS behaviour on existing connections (ADR-019)
     * in connections.options.tls, without changing it:
     *
     * - pgsql: `prefer` (what DynamicConnectionFactory hard-coded), flagged
     *   "not_enforced" because it silently falls back to plaintext.
     * - mysql / mariadb / sqlsrv: mode left unset (null), flagged
     *   "driver_default". No explicit "off" value is written: on drivers that
     *   encrypt by default that would be a silent downgrade.
     * - sqlite: untouched.
     *
     * New connections get their required-TLS defaults from the Connection model.
     */
    public function up(): void
    {
        DB::table('connections')
            ->whereIn('driver', ['pgsql', 'mysql', 'mariadb', 'sqlsrv'])
            ->orderBy('id')
            ->each(function (object $row) {
                $options = $this->decode($row->options);

                if (isset($options['tls'])) {
                    return;
                }

                $options['tls'] = $row->driver === 'pgsql'
                    ? ['mode' => 'prefer', 'flag' => 'not_enforced']
                    : ['mode' => null, 'flag' => 'driver_default'];

                DB::table('connections')->where('id', $row->id)->update(['options' => json_encode($options)]);
            });
    }

    /**
     * Drops the recorded setting (PEM material included) from every row.
     * PEM files under storage/app/tls/ are not touched here.
     */
    public function down(): void
    {
        DB::table('connections')
            ->whereNotNull('options')
            ->orderBy('id')
            ->each(function (object $row) {
                $options = $this->decode($row->options);

                if (! array_key_exists('tls', $options)) {
                    return;
                }

                unset($options['tls']);

                DB::table('connections')->where('id', $row->id)->update([
                    'options' => $options === [] ? null : json_encode($options),
                ]);
            });
    }

    /** @return array<string, mixed> */
    private function decode(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
