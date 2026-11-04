<?php

namespace App\Services\Secrets\Drivers;

use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException as Failure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HashiCorp Vault, KV secrets engine v2 (v1 optional), over the HTTP API.
 *
 * Authentication is chosen with QUERYPROXY_VAULT_AUTH: `token` uses
 * QUERYPROXY_VAULT_TOKEN as is; `approle` and `kubernetes` log in and keep the
 * resulting client token in this object (process memory) until shortly before
 * its lease ends. A 403 on a token obtained by login triggers one fresh login,
 * so a revoked token heals itself. Configuration: config('queryproxy.secrets.vault').
 */
class VaultDriver implements SecretDriver
{
    /** Seconds before the lease end at which a login token is replaced. */
    private const LEASE_MARGIN = 30;

    private ?string $loginToken = null;

    private int $loginExpires = 0;

    public function scheme(): string
    {
        return SecretReference::VAULT;
    }

    public function isConfigured(): bool
    {
        return $this->config('address') !== '';
    }

    public function diagnose(): array
    {
        $problems = [];
        $warnings = [];
        $address = $this->config('address');
        $auth = $this->auth();

        $parts = $address === '' ? false : parse_url($address);

        if ($address === '') {
            $problems[] = 'QUERYPROXY_VAULT_ADDR is not set.';
        } elseif ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            $problems[] = 'QUERYPROXY_VAULT_ADDR is not a valid http(s) URL.';
        } elseif (($parts['scheme'] ?? '') === 'http' && ! in_array($parts['host'], ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $warnings[] = 'QUERYPROXY_VAULT_ADDR uses plain http to a non-local host; the token and the secrets cross the network unencrypted.';
        }

        if (! in_array($auth, ['token', 'approle', 'kubernetes'], true)) {
            $problems[] = 'QUERYPROXY_VAULT_AUTH must be token, approle or kubernetes.';
        }

        if ($auth === 'token' && $this->config('token') === '') {
            $problems[] = 'QUERYPROXY_VAULT_TOKEN (or QUERYPROXY_VAULT_TOKEN_FILE) is not set.';
        }

        if ($auth === 'approle' && ($this->config('role_id') === '' || $this->config('secret_id') === '')) {
            $problems[] = 'AppRole login needs QUERYPROXY_VAULT_ROLE_ID and QUERYPROXY_VAULT_SECRET_ID (or QUERYPROXY_VAULT_SECRET_ID_FILE).';
        }

        if ($auth === 'kubernetes') {
            if ($this->config('kubernetes_role') === '') {
                $problems[] = 'Kubernetes login needs QUERYPROXY_VAULT_K8S_ROLE.';
            }

            if (! is_readable($this->config('kubernetes_jwt_path'))) {
                $problems[] = 'The service account token file QUERYPROXY_VAULT_K8S_JWT_PATH is not readable.';
            }
        }

        if (! in_array($this->kvVersion(), [1, 2], true)) {
            $problems[] = 'QUERYPROXY_VAULT_KV_VERSION must be 1 or 2.';
        }

        $mounts = (array) config('queryproxy.secrets.vault.mounts', []);

        if ($this->kvVersion() === 1 && $mounts === []) {
            $problems[] = 'QUERYPROXY_VAULT_MOUNTS is required with KV v1 (the KV mounts that may be read).';
        }

        foreach ($mounts as $mount) {
            if (in_array(strtolower(explode('/', (string) $mount)[0]), SecretReference::VAULT_RESERVED_MOUNTS, true)) {
                $problems[] = "QUERYPROXY_VAULT_MOUNTS lists \"{$mount}\", which is not a KV mount.";
            }
        }

        $ca = $this->config('ca_file');

        if ($ca !== '' && ! is_readable($ca)) {
            $problems[] = 'QUERYPROXY_VAULT_CA_FILE is not readable.';
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    public function fetch(SecretReference $reference): string
    {
        if ($this->config('address') === '') {
            throw new Failure(Failure::NOT_CONFIGURED, $reference->scheme, $reference->location);
        }

        try {
            $response = $this->read($reference, $this->clientToken(false, $reference));

            // A token from a login can be revoked or expired early: log in once more.
            if ($response->status() === 403 && $this->auth() !== 'token') {
                $response = $this->read($reference, $this->clientToken(true, $reference));
            }

            return $this->extract($response, $reference);
        } catch (Failure $e) {
            throw $e->forReference($reference);
        } catch (Throwable $e) {
            // Includes ConnectionException; its message is dropped, only the class is kept.
            throw (new Failure(Failure::UNAVAILABLE, $reference->scheme, $reference->location))->causedBy($e);
        }
    }

    private function read(SecretReference $reference, string $token): Response
    {
        [$mount, $path] = $this->mountAndPath($reference);

        $mount = implode('/', array_map('rawurlencode', explode('/', $mount)));
        $path = implode('/', array_map('rawurlencode', explode('/', $path)));

        $url = $this->kvVersion() === 1
            ? "/v1/{$mount}/{$path}"
            : "/v1/{$mount}/data/{$path}";

        return $this->http()->withHeaders(['X-Vault-Token' => $token])->get($url);
    }

    /**
     * Split the reference location into the KV mount and the path inside it.
     *
     * Only KV mounts may be read. With QUERYPROXY_VAULT_MOUNTS set, the mount
     * is the longest listed mount the location starts with (so `kv/prod` can
     * be a mount) and nothing else is read. Without it the first segment is the
     * mount, which is only safe on KV v2 where the request path carries
     * `/data/`; KV v1 would turn any API path into a read (the parse step
     * already refuses auth/, sys/, identity/ and cubbyhole/), so there the list
     * is required.
     *
     * @return array{0: string, 1: string}
     *
     * @throws Failure not_configured
     */
    private function mountAndPath(SecretReference $reference): array
    {
        $mounts = array_filter((array) config('queryproxy.secrets.vault.mounts', []), fn ($mount) => is_string($mount) && $mount !== ''
            && ! in_array(strtolower(explode('/', $mount)[0]), SecretReference::VAULT_RESERVED_MOUNTS, true));
        usort($mounts, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($mounts as $mount) {
            if (str_starts_with($reference->location, $mount.'/')) {
                return [$mount, substr($reference->location, strlen($mount) + 1)];
            }
        }

        if ($mounts !== [] || $this->kvVersion() === 1) {
            throw new Failure(Failure::NOT_CONFIGURED);
        }

        $parts = $reference->parts;
        $mount = array_shift($parts);

        return [$mount, implode('/', $parts)];
    }

    private function extract(Response $response, SecretReference $reference): string
    {
        $status = $response->status();

        if ($status === 404) {
            throw new Failure(Failure::NOT_FOUND, status: $status);
        }

        if ($status === 401 || $status === 403) {
            throw new Failure(Failure::ACCESS_DENIED, status: $status);
        }

        if ($status < 200 || $status >= 300) {
            throw new Failure(Failure::UNAVAILABLE, status: $status);
        }

        $body = $response->json();
        $data = $this->kvVersion() === 1 ? ($body['data'] ?? null) : ($body['data']['data'] ?? null);

        if (! is_array($data)) {
            throw new Failure(Failure::MALFORMED, status: $status);
        }

        if (! array_key_exists((string) $reference->key, $data)) {
            throw new Failure(Failure::KEY_MISSING, status: $status);
        }

        $value = $data[(string) $reference->key];

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new Failure(Failure::MALFORMED, status: $status);
        }

        return (string) $value;
    }

    private function clientToken(bool $forceLogin, SecretReference $reference): string
    {
        $auth = $this->auth();

        if ($auth === 'token') {
            $token = $this->config('token');

            return $token !== '' ? $token : throw new Failure(Failure::NOT_CONFIGURED);
        }

        if (! $forceLogin && $this->loginToken !== null && $this->loginExpires > now()->getTimestamp()) {
            return $this->loginToken;
        }

        $this->loginToken = null;

        [$mount, $payload] = match ($auth) {
            'approle' => [$this->config('approle_mount'), [
                'role_id' => $this->config('role_id'),
                'secret_id' => $this->config('secret_id'),
            ]],
            'kubernetes' => [$this->config('kubernetes_mount'), [
                'role' => $this->config('kubernetes_role'),
                'jwt' => $this->serviceAccountJwt(),
            ]],
            default => throw new Failure(Failure::NOT_CONFIGURED),
        };

        if (in_array('', $payload, true) || $mount === '') {
            throw new Failure(Failure::NOT_CONFIGURED);
        }

        $response = $this->http()->post('/v1/auth/'.implode('/', array_map('rawurlencode', explode('/', trim($mount, '/')))).'/login', $payload);

        if ($response->status() !== 200) {
            throw new Failure(Failure::AUTH_FAILED, status: $response->status());
        }

        $token = $response->json('auth.client_token');

        if (! is_string($token) || $token === '') {
            throw new Failure(Failure::MALFORMED, status: $response->status());
        }

        $lease = (int) $response->json('auth.lease_duration', 0);

        $this->loginToken = $token;
        // A token without a lease (0 = does not expire) is still replaced every few minutes.
        $this->loginExpires = now()->getTimestamp() + ($lease > 0 ? max(1, $lease - self::LEASE_MARGIN) : 300);

        return $token;
    }

    private function serviceAccountJwt(): string
    {
        $path = $this->config('kubernetes_jwt_path');
        $jwt = is_readable($path) ? @file_get_contents($path) : false;

        return is_string($jwt) ? trim($jwt) : '';
    }

    private function http(): PendingRequest
    {
        $timeout = max(1, (int) config('queryproxy.secrets.timeout', 5));
        $request = Http::baseUrl(rtrim($this->config('address'), '/'))
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->withoutRedirecting();

        if (($namespace = $this->config('namespace')) !== '') {
            $request = $request->withHeaders(['X-Vault-Namespace' => $namespace]);
        }

        if (($ca = $this->config('ca_file')) !== '') {
            $request = $request->withOptions(['verify' => $ca]);
        }

        return $request;
    }

    private function kvVersion(): int
    {
        return (int) $this->config('kv_version');
    }

    private function auth(): string
    {
        return strtolower($this->config('auth'));
    }

    private function config(string $key): string
    {
        return trim((string) config("queryproxy.secrets.vault.{$key}", ''));
    }
}
