<?php

use App\Services\Secrets\Drivers\AzureKeyVaultDriver;
use App\Services\Secrets\SecretResolutionException as Failure;
use App\Services\Secrets\SecretResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecretsWorld;

if (! function_exists('secretTestFailure')) {
    /** The failure thrown by $callback, or null. */
    function secretTestFailure(Closure $callback): ?Failure
    {
        try {
            $callback();
        } catch (Failure $e) {
            return $e;
        }

        return null;
    }
}

function azureTestConfig(array $overrides = []): void
{
    config([
        'queryproxy.secrets.cache_ttl' => 300,

        'queryproxy.secrets.azure' => array_merge([
            'auth' => 'client_secret',
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'AZ-CLIENT-SECRET',
            'federated_token_file' => '',
            'authority_host' => '',
            'vault_dns_suffix' => '',
        ], $overrides),
    ]);
}

function azureTestFake(array $tokenBody = ['access_token' => 'az.token', 'expires_in' => 3600], int $tokenStatus = 200, array $secretBody = ['value' => 's3cr3t-azure'], int $secretStatus = 200): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response($tokenBody, $tokenStatus),
        '*.vault.azure.net/*' => Http::response($secretBody, $secretStatus),
    ]);
}

test('a secret resolves with a client secret login', function () {
    azureTestConfig();
    azureTestFake();

    expect(app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password', SecretsWorld::connection()))->toBe('s3cr3t-azure');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token'
        && $request['grant_type'] === 'client_credentials'
        && $request['client_secret'] === 'AZ-CLIENT-SECRET'
        && $request['scope'] === 'https://vault.azure.net/.default');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://corp-vault.vault.azure.net/secrets/db-password?api-version=7.4')
        && $request->header('Authorization') === ['Bearer az.token']);
});

test('a pinned version is part of the path', function () {
    azureTestConfig();
    azureTestFake();

    app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password/0123456789abcdef', SecretsWorld::connection());

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/secrets/db-password/0123456789abcdef?'));
});

test('workload identity sends the federated token as the client assertion', function () {
    $file = tempnam(sys_get_temp_dir(), 'qp_fed_');
    file_put_contents($file, "federated.jwt\n");
    azureTestConfig(['auth' => 'auto', 'client_secret' => '', 'federated_token_file' => $file]);
    azureTestFake();

    app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password', SecretsWorld::connection());

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/v2.0/token')
        && $request['client_assertion'] === 'federated.jwt'
        && $request['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        && ! isset($request['client_secret']));

    @unlink($file);
});

test('managed identity asks the instance metadata endpoint', function () {
    azureTestConfig(['auth' => 'managed_identity', 'client_secret' => '', 'client_id' => '']);
    Http::fake([
        '169.254.169.254/*' => Http::response(['access_token' => 'imds.token', 'expires_in' => '3599']),
        '*.vault.azure.net/*' => Http::response(['value' => 's3cr3t-azure']),
    ]);

    expect(app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password', SecretsWorld::connection()))->toBe('s3cr3t-azure');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://169.254.169.254/metadata/identity/oauth2/token')
        && $request->header('Metadata') === ['true']
        && $request['resource'] === 'https://vault.azure.net');
});

test('the access token is reused across secrets', function () {
    azureTestConfig();
    azureTestFake();

    $resolver = app(SecretResolver::class);
    $resolver->resolve('azure-kv://corp-vault/one', SecretsWorld::connection());
    $resolver->resolve('azure-kv://corp-vault/two', SecretsWorld::connection());

    Http::assertSentCount(3); // one token request, two secret reads
});

test('Azure failures map to fixed reasons', function (int $tokenStatus, int $secretStatus, array $secretBody, string $reason) {
    azureTestConfig();
    azureTestFake(['access_token' => 'az.token', 'expires_in' => 3600], $tokenStatus, $secretBody + ['error' => 'LEAK'], $secretStatus);

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password', SecretsWorld::connection()));

    expect($failure->reason)->toBe($reason)
        ->and($failure->getMessage())->not->toContain('LEAK')
        ->and($failure->getMessage())->not->toContain('AZ-CLIENT-SECRET');
})->with([
    'login refused' => [401, 200, [], Failure::AUTH_FAILED],
    'forbidden' => [200, 403, [], Failure::ACCESS_DENIED],
    'not found' => [200, 404, [], Failure::NOT_FOUND],
    'server error' => [200, 500, [], Failure::UNAVAILABLE],
    'no value' => [200, 200, ['other' => 1], Failure::MALFORMED],
]);

test('a revoked cached token is replaced once on a 401', function () {
    azureTestConfig();
    Http::fake([
        'login.microsoftonline.com/*' => Http::sequence()
            ->push(['access_token' => 'old', 'expires_in' => 3600])
            ->push(['access_token' => 'new', 'expires_in' => 3600]),
        '*.vault.azure.net/*' => Http::sequence()
            ->push([], 401)
            ->push(['value' => 's3cr3t-azure']),
    ]);

    expect(app(SecretResolver::class)->resolve('azure-kv://corp-vault/db-password', SecretsWorld::connection()))->toBe('s3cr3t-azure');
});

test('azure diagnosis lists the missing client secret settings', function () {
    azureTestConfig(['client_secret' => '', 'auth' => 'client_secret']);

    $driver = app(AzureKeyVaultDriver::class);

    expect(implode(' ', $driver->diagnose()['problems']))->toContain('QUERYPROXY_AZURE_CLIENT_SECRET')
        ->and($driver->isConfigured())->toBeTrue();
});
