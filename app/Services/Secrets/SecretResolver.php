<?php

namespace App\Services\Secrets;

use App\Enums\AuditEvent;
use App\Models\Connection;
use App\Services\Secrets\Drivers\AwsSecretsManagerDriver;
use App\Services\Secrets\Drivers\AzureKeyVaultDriver;
use App\Services\Secrets\Drivers\SecretDriver;
use App\Services\Secrets\Drivers\VaultDriver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns an external secret reference into its value (ADR-028).
 *
 * Fail-closed: any problem ends in a SecretResolutionException with a fixed,
 * value-free message; the detail (scheme, location, reason, HTTP status, class
 * of the underlying error) goes to the log and the audit record, never the
 * value, a token or a response body.
 *
 * Resolved values are cached in this object only, that is in the memory of the
 * current process, for QUERYPROXY_SECRET_CACHE_TTL seconds. Laravel's cache
 * store is deliberately not used: it would persist the value outside the
 * process. A worker that is restarted starts with an empty cache.
 */
class SecretResolver
{
    /** @var array<string, array{value: string, expires: int}> */
    private array $cache = [];

    /** @var array<string, SecretDriver> */
    private array $drivers = [];

    /** @param  list<SecretDriver>|null  $drivers  defaults to the three built-in drivers, built from the container on first use */
    public function __construct(?array $drivers = null)
    {
        foreach ($drivers ?? [] as $driver) {
            $this->drivers[$driver->scheme()] = $driver;
        }
    }

    /**
     * Resolve a reference for a connection.
     *
     * The reference must fall under one of the connection's team's prefixes
     * (ADR-028 amendment). That check comes first, before the cache is looked
     * at: the cache is keyed by the reference alone, so without it a team could
     * read what another team's connection resolved a moment ago.
     *
     * @param  Connection  $connection  the connection being opened; failures are audited against it
     * @param  string  $field  which credential of the connection holds the reference (password | read_password)
     *
     * @throws SecretResolutionException
     */
    public function resolve(string $reference, Connection $connection, string $field = 'password'): string
    {
        $reference = trim($reference);
        $parsed = null;

        try {
            $parsed = SecretReference::parse($reference);

            if (($team = $connection->currentTeam()) === null || ! $team->allowsSecretReference($parsed)) {
                throw new SecretResolutionException(SecretResolutionException::NOT_ALLOWED);
            }

            if (($cached = $this->peek($reference)) !== null) {
                return $cached;
            }

            $value = $this->driver($parsed->scheme)->fetch($parsed);

            if ($value === '') {
                throw new SecretResolutionException(SecretResolutionException::KEY_MISSING);
            }
        } catch (SecretResolutionException $e) {
            $this->failed($parsed ? $e->forReference($parsed) : $e, $connection, $field);
        } catch (Throwable $e) {
            // A driver must only throw SecretResolutionException; if anything else
            // escapes, keep its class and drop its message (it may quote a response).
            $this->failed(
                (new SecretResolutionException(SecretResolutionException::UNAVAILABLE, $parsed?->scheme, $parsed?->location))->causedBy($e),
                $connection,
                $field,
            );
        }

        $this->remember($reference, $value);

        return $value;
    }

    /** The cached value of a reference if it is still fresh; never fetches. */
    public function peek(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $key = $this->cacheKey(trim($reference));
        $entry = $this->cache[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] <= now()->getTimestamp()) {
            unset($this->cache[$key]);

            return null;
        }

        return $entry['value'];
    }

    /** Drop every cached value. */
    public function flush(): void
    {
        $this->cache = [];
    }

    public function driver(string $scheme): SecretDriver
    {
        return $this->drivers[$scheme] ??= match ($scheme) {
            SecretReference::VAULT => app(VaultDriver::class),
            SecretReference::AWS => app(AwsSecretsManagerDriver::class),
            SecretReference::AZURE => app(AzureKeyVaultDriver::class),
            default => throw new SecretResolutionException(SecretResolutionException::INVALID_REFERENCE, $scheme),
        };
    }

    private function remember(string $reference, string $value): void
    {
        $ttl = max(0, (int) config('queryproxy.secrets.cache_ttl', 300));

        if ($ttl === 0) {
            return;
        }

        // Drop what has expired so a long-lived worker does not collect dead entries.
        $now = now()->getTimestamp();
        $this->cache = array_filter($this->cache, fn (array $entry) => $entry['expires'] > $now);

        $this->cache[$this->cacheKey($reference)] = ['value' => $value, 'expires' => $now + $ttl];
    }

    private function cacheKey(string $reference): string
    {
        return hash('sha256', $reference);
    }

    /**
     * Log and audit the failure, then rethrow it.
     *
     * @throws SecretResolutionException
     */
    private function failed(SecretResolutionException $e, Connection $connection, string $field): never
    {
        $detail = array_filter([
            'connection_id' => $connection->id,
            'field' => $field,
            'scheme' => $e->scheme,
            'location' => $e->location,
            'reason' => $e->reason,
            'status' => $e->status,
            'exception' => $e->cause,
        ], fn ($value) => $value !== null);

        Log::warning('A secret reference could not be resolved.', $detail);

        try {
            audit()->record(
                AuditEvent::SecretResolveFailed,
                connection: $connection,
                metadata: array_filter([
                    'field' => $field,
                    'scheme' => $e->scheme,
                    'location' => $e->location,
                    'reason' => $e->reason,
                ], fn ($value) => $value !== null),
                system: auth()->guest(),
            );
        } catch (Throwable $audit) {
            // The audit write must not hide the original failure.
            Log::error('Recording a failed secret resolution failed.', ['exception' => $audit::class]);
        }

        throw $e;
    }
}
