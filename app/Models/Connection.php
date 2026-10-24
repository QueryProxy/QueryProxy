<?php

namespace App\Models;

use App\Enums\DbDriver;
use App\Services\Connections\TlsPemStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

#[Fillable([
    'team_id', 'name', 'driver', 'host', 'port', 'database',
    'username', 'password', 'read_username', 'read_password', 'options', 'created_by',
])]
class Connection extends Model
{
    use HasFactory;

    /** PEM material kept (encrypted) in options.tls.pem. */
    public const TLS_PEM_KINDS = ['ca', 'cert', 'key'];

    /** PostgreSQL sslmode values that never fall back to plaintext. */
    public const TLS_PGSQL_ENFORCING_MODES = ['require', 'verify-ca', 'verify-full'];

    public const TLS_PGSQL_MODES = ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /** MySQL/MariaDB and SQL Server: TLS is required (null mode = left to the driver default). */
    public const TLS_MODE_REQUIRED = 'required';

    public const TLS_ENFORCED = 'enforced';

    public const TLS_NOT_ENFORCED = 'not_enforced';

    public const TLS_DRIVER_DEFAULT = 'driver_default';

    public const TLS_NONE = 'none';

    protected static function booted(): void
    {
        // New connections require TLS on every network driver (ADR-019).
        static::creating(function (self $connection) {
            if ($connection->tlsSettings() === [] && ($defaults = self::defaultTlsSettings($connection->driver)) !== null) {
                $connection->options = array_merge($connection->options ?? [], ['tls' => $defaults]);
            }
        });

        static::deleted(fn (self $connection) => app(TlsPemStore::class)->purge($connection));
    }

    protected function casts(): array
    {
        return [
            'driver' => DbDriver::class,
            'host' => 'encrypted',
            'port' => 'encrypted',
            'database' => 'encrypted',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'read_username' => 'encrypted',
            'read_password' => 'encrypted',
            'options' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Developers explicitly granted access to this connection. */
    public function grantedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function queryRequests(): HasMany
    {
        return $this->hasMany(QueryRequest::class);
    }

    public function maskingRules(): HasMany
    {
        return $this->hasMany(MaskingRule::class);
    }

    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * Whether SELECTs should run as a dedicated read-only database user.
     */
    public function hasReadCredentials(): bool
    {
        return is_string($this->read_username) && trim($this->read_username) !== '';
    }

    public function isGrantedTo(User $user): bool
    {
        return $this->grantedUsers()->whereKey($user->id)->exists();
    }

    /**
     * TLS settings of a brand-new connection, or null for drivers outside the rule.
     *
     * @return array<string, mixed>|null
     */
    public static function defaultTlsSettings(?DbDriver $driver): ?array
    {
        return match ($driver) {
            DbDriver::Pgsql => ['mode' => 'require'],
            DbDriver::Mysql, DbDriver::Mariadb => ['mode' => self::TLS_MODE_REQUIRED, 'verify_server_cert' => false],
            DbDriver::Sqlsrv => ['mode' => self::TLS_MODE_REQUIRED, 'trust_server_certificate' => false],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function tlsSettings(): array
    {
        $tls = ($this->options ?? [])['tls'] ?? [];

        return is_array($tls) ? $tls : [];
    }

    /**
     * Effective TLS mode. A connection without a stored setting behaves as it
     * did before the setting existed: PostgreSQL `prefer`, others unset (null).
     */
    public function tlsMode(): ?string
    {
        $mode = $this->tlsSettings()['mode'] ?? null;
        $mode = is_string($mode) && $mode !== '' ? $mode : null;

        return $mode ?? ($this->driver === DbDriver::Pgsql ? 'prefer' : null);
    }

    /** One of the TLS_* state constants; drives the "TLS not enforced" flags. */
    public function tlsState(): string
    {
        return match ($this->driver) {
            DbDriver::Pgsql => in_array($this->tlsMode(), self::TLS_PGSQL_ENFORCING_MODES, true)
                ? self::TLS_ENFORCED
                : self::TLS_NOT_ENFORCED,
            DbDriver::Mysql, DbDriver::Mariadb, DbDriver::Sqlsrv => $this->tlsMode() === self::TLS_MODE_REQUIRED
                ? self::TLS_ENFORCED
                : self::TLS_DRIVER_DEFAULT,
            default => self::TLS_NONE,
        };
    }

    public function tlsFlag(string $key, bool $default = false): bool
    {
        $value = $this->tlsSettings()[$key] ?? $default;

        return (bool) $value;
    }

    public function hasTlsPem(string $kind): bool
    {
        $pem = $this->tlsSettings()['pem'][$kind] ?? null;

        return is_string($pem) && $pem !== '';
    }

    /**
     * Decrypted PEM text, or null when none is stored.
     *
     * @throws RuntimeException when a stored PEM cannot be decrypted (typically APP_KEY changed
     *                          without APP_PREVIOUS_KEYS); silently treating it as absent would
     *                          connect without the CA or client certificate and hide the cause
     */
    public function tlsPem(string $kind): ?string
    {
        if (! $this->hasTlsPem($kind)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->tlsSettings()['pem'][$kind]);
        } catch (DecryptException $e) {
            throw new RuntimeException("The stored TLS {$kind} PEM of connection #{$this->getKey()} cannot be decrypted; the application key may have changed. Replace or remove the stored value.", 0, $e);
        }
    }

    /** Whether the factory hands this connection's PEM files to the driver (PostgreSQL always, MySQL/MariaDB when TLS is required). */
    public function usesTlsPemFiles(): bool
    {
        return match ($this->driver) {
            DbDriver::Pgsql => true,
            DbDriver::Mysql, DbDriver::Mariadb => $this->tlsMode() === self::TLS_MODE_REQUIRED,
            default => false,
        };
    }

    /** Store (encrypted) or clear one PEM; the caller saves the model. */
    public function setTlsPem(string $kind, ?string $pem): void
    {
        $options = $this->options ?? [];
        $tls = $this->tlsSettings();

        if ($pem === null || trim($pem) === '') {
            unset($tls['pem'][$kind]);
        } else {
            $tls['pem'][$kind] = Crypt::encryptString(trim($pem)."\n");
        }

        if (($tls['pem'] ?? null) === []) {
            unset($tls['pem']);
        }

        $options['tls'] = $tls;
        $this->options = $options;
    }
}
