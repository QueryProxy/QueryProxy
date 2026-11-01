<?php

namespace App\Services\Secrets\Drivers;

use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException as Failure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Azure Key Vault over the REST API.
 *
 * The access token comes from QUERYPROXY_AZURE_AUTH:
 *   client_secret      tenant + client id + client secret (QUERYPROXY_AZURE_*)
 *   workload_identity  federated token file (AKS workload identity); the
 *                      AZURE_TENANT_ID / AZURE_CLIENT_ID / AZURE_FEDERATED_TOKEN_FILE
 *                      variables the AKS webhook injects are honoured
 *   managed_identity   IMDS or, on App Service / Functions, IDENTITY_ENDPOINT
 *   auto (default)     client_secret if a client secret is set, else
 *                      workload_identity if a token file is set, else managed_identity
 * The token is kept in this object (process memory) until shortly before it
 * expires. The vault host is built from the validated vault name and a fixed
 * DNS suffix (vault.azure.net unless QUERYPROXY_AZURE_VAULT_DNS_SUFFIX says
 * otherwise), so a reference cannot steer a request to another host.
 */
class AzureKeyVaultDriver implements SecretDriver
{
    private const API_VERSION = '7.4';

    private const TOKEN_MARGIN = 60;

    private const IMDS_URL = 'http://169.254.169.254/metadata/identity/oauth2/token';

    private ?string $token = null;

    private int $tokenExpires = 0;

    public function scheme(): string
    {
        return SecretReference::AZURE;
    }

    public function isConfigured(): bool
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'federated_token_file'] as $key) {
            if ($this->config($key) !== '') {
                return true;
            }
        }

        return ! in_array($this->config('auth'), ['', 'auto'], true)
            || $this->env('IDENTITY_ENDPOINT') !== '';
    }

    public function diagnose(): array
    {
        $problems = [];
        $warnings = [];
        $mode = $this->mode();

        if (! in_array($mode, ['client_secret', 'workload_identity', 'managed_identity'], true)) {
            $problems[] = 'QUERYPROXY_AZURE_AUTH must be auto, client_secret, workload_identity or managed_identity.';

            return ['problems' => $problems, 'warnings' => $warnings];
        }

        if ($mode === 'client_secret') {
            foreach (['tenant_id' => 'QUERYPROXY_AZURE_TENANT_ID', 'client_id' => 'QUERYPROXY_AZURE_CLIENT_ID', 'client_secret' => 'QUERYPROXY_AZURE_CLIENT_SECRET'] as $key => $name) {
                if ($this->config($key) === '') {
                    $problems[] = "{$name} is not set (needed for client secret login).";
                }
            }
        }

        if ($mode === 'workload_identity') {
            foreach (['tenant_id' => 'QUERYPROXY_AZURE_TENANT_ID', 'client_id' => 'QUERYPROXY_AZURE_CLIENT_ID'] as $key => $name) {
                if ($this->config($key) === '') {
                    $problems[] = "{$name} is not set (needed for workload identity).";
                }
            }

            if (! is_readable($this->config('federated_token_file'))) {
                $problems[] = 'QUERYPROXY_AZURE_FEDERATED_TOKEN_FILE is not set or not readable.';
            }
        }

        if ($mode === 'managed_identity' && $this->env('IDENTITY_ENDPOINT') !== '' && $this->env('IDENTITY_HEADER') === '') {
            $problems[] = 'IDENTITY_ENDPOINT is set without IDENTITY_HEADER.';
        }

        if ($this->config('authority_host') !== '' && ! str_starts_with($this->config('authority_host'), 'https://')) {
            $warnings[] = 'QUERYPROXY_AZURE_AUTHORITY_HOST is not an https URL.';
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    public function fetch(SecretReference $reference): string
    {
        try {
            $response = $this->request($reference, $this->accessToken($reference));

            if ($response->status() === 401) {
                // The cached token may have been revoked: get a fresh one once.
                $response = $this->request($reference, $this->accessToken($reference, refresh: true));
            }
        } catch (Failure $e) {
            throw $e->forReference($reference);
        } catch (Throwable $e) {
            throw (new Failure(Failure::UNAVAILABLE, $reference->scheme, $reference->location))->causedBy($e);
        }

        $status = $response->status();
        $fail = fn (string $reason) => new Failure($reason, $reference->scheme, $reference->location, $status);

        if ($status === 404) {
            throw $fail(Failure::NOT_FOUND);
        }

        if ($status === 401 || $status === 403) {
            throw $fail(Failure::ACCESS_DENIED);
        }

        if ($status < 200 || $status >= 300) {
            throw $fail(Failure::UNAVAILABLE);
        }

        $value = $response->json('value');

        if (! is_string($value)) {
            throw $fail(Failure::MALFORMED);
        }

        return $value;
    }

    private function request(SecretReference $reference, string $token): Response
    {
        [$vault, $name] = $reference->parts;
        $version = $reference->parts[2] ?? null;

        $suffix = $this->config('vault_dns_suffix') ?: 'vault.azure.net';
        $path = '/secrets/'.rawurlencode($name).($version !== null ? '/'.rawurlencode($version) : '');

        return $this->http()
            ->withToken($token)
            ->get('https://'.strtolower($vault).'.'.$suffix.$path, ['api-version' => self::API_VERSION]);
    }

    private function accessToken(SecretReference $reference, bool $refresh = false): string
    {
        if (! $refresh && $this->token !== null && $this->tokenExpires > now()->getTimestamp()) {
            return $this->token;
        }

        $this->token = null;

        $response = match ($this->mode()) {
            'client_secret' => $this->clientSecretLogin(),
            'workload_identity' => $this->workloadIdentityLogin(),
            'managed_identity' => $this->managedIdentityLogin(),
            default => throw new Failure(Failure::NOT_CONFIGURED),
        };

        if ($response->status() !== 200) {
            throw new Failure(Failure::AUTH_FAILED, status: $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new Failure(Failure::MALFORMED, status: $response->status());
        }

        $lifetime = (int) $response->json('expires_in', 0);

        if ($lifetime <= 0 && ($onExpiry = (int) $response->json('expires_on', 0)) > 0) {
            $lifetime = $onExpiry - now()->getTimestamp();
        }

        $this->token = $token;
        $this->tokenExpires = now()->getTimestamp() + max(1, ($lifetime > 0 ? $lifetime : 300) - self::TOKEN_MARGIN);

        return $token;
    }

    private function clientSecretLogin(): Response
    {
        $this->requireConfig(['tenant_id', 'client_id', 'client_secret']);

        return $this->tokenEndpoint([
            'grant_type' => 'client_credentials',
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'scope' => $this->resource().'/.default',
        ]);
    }

    private function workloadIdentityLogin(): Response
    {
        $this->requireConfig(['tenant_id', 'client_id', 'federated_token_file']);

        $path = $this->config('federated_token_file');
        $assertion = is_readable($path) ? @file_get_contents($path) : false;

        if (! is_string($assertion) || trim($assertion) === '') {
            throw new Failure(Failure::NOT_CONFIGURED);
        }

        return $this->tokenEndpoint([
            'grant_type' => 'client_credentials',
            'client_id' => $this->config('client_id'),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => trim($assertion),
            'scope' => $this->resource().'/.default',
        ]);
    }

    private function managedIdentityLogin(): Response
    {
        $clientId = $this->config('client_id');

        if (($endpoint = $this->env('IDENTITY_ENDPOINT')) !== '') {
            // App Service / Functions.
            return $this->http()
                ->withHeaders(['X-IDENTITY-HEADER' => $this->env('IDENTITY_HEADER')])
                ->get($endpoint, array_filter([
                    'api-version' => '2019-08-01',
                    'resource' => $this->resource(),
                    'client_id' => $clientId ?: null,
                ]));
        }

        return $this->http()
            ->withHeaders(['Metadata' => 'true'])
            ->get(self::IMDS_URL, array_filter([
                'api-version' => '2018-02-01',
                'resource' => $this->resource(),
                'client_id' => $clientId ?: null,
            ]));
    }

    /** @param  array<string, string>  $form */
    private function tokenEndpoint(array $form): Response
    {
        $authority = rtrim($this->config('authority_host') ?: 'https://login.microsoftonline.com', '/');

        return $this->http()
            ->asForm()
            ->post($authority.'/'.rawurlencode($this->config('tenant_id')).'/oauth2/v2.0/token', $form);
    }

    /** @param  list<string>  $keys */
    private function requireConfig(array $keys): void
    {
        foreach ($keys as $key) {
            if ($this->config($key) === '') {
                throw new Failure(Failure::NOT_CONFIGURED);
            }
        }
    }

    private function mode(): string
    {
        $mode = strtolower($this->config('auth') ?: 'auto');

        if ($mode !== 'auto') {
            return $mode;
        }

        return match (true) {
            $this->config('client_secret') !== '' => 'client_secret',
            $this->config('federated_token_file') !== '' => 'workload_identity',
            default => 'managed_identity',
        };
    }

    private function resource(): string
    {
        return 'https://'.($this->config('vault_dns_suffix') ?: 'vault.azure.net');
    }

    private function http(): PendingRequest
    {
        $timeout = max(1, (int) config('queryproxy.secrets.timeout', 5));

        return Http::acceptJson()->timeout($timeout)->connectTimeout($timeout)->withoutRedirecting();
    }

    private function config(string $key): string
    {
        return trim((string) config("queryproxy.secrets.azure.{$key}", ''));
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
