<?php

/*
 * External secret references against a real HashiCorp Vault (ADR-028): the
 * Vault driver over HTTP with token and AppRole login, KV v2 and KV v1, and
 * the refusals a real Vault gives (no policy, no path, no key).
 *
 * The server is the dev-mode container of docker-compose.integration.yml:
 *
 *   docker compose -f docker-compose.integration.yml up -d --wait vault
 *   QUERYPROXY_IT_VAULT_ADDR=http://127.0.0.1:18200 \
 *   QUERYPROXY_IT_VAULT_TOKEN=queryproxy-test-root \
 *   ./vendor/bin/pest --group=integration --filter=VaultSecret
 *
 * Group "integration"; skipped without QUERYPROXY_IT_VAULT_ADDR. CI sets
 * QUERYPROXY_IT_VAULT_REQUIRED=1 so a missing server fails the job instead.
 * The Kubernetes login needs a cluster and is covered with a faked Vault in
 * tests/Feature/Secrets.
 */

use App\Models\Connection;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Secrets\SecretResolutionException as Failure;
use App\Services\Secrets\SecretResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecretsWorld;

beforeEach(function () {
    $this->vaultAddress = rtrim((string) getenv('QUERYPROXY_IT_VAULT_ADDR'), '/');
    $this->vaultRoot = (string) getenv('QUERYPROXY_IT_VAULT_TOKEN');

    if ($this->vaultAddress === '' || $this->vaultRoot === '') {
        if (getenv('QUERYPROXY_IT_VAULT_REQUIRED') === '1') {
            $this->fail('QUERYPROXY_IT_VAULT_ADDR and QUERYPROXY_IT_VAULT_TOKEN must be set (see docker-compose.integration.yml).');
        }

        $this->markTestSkipped('Set QUERYPROXY_IT_VAULT_ADDR and QUERYPROXY_IT_VAULT_TOKEN to run the Vault integration tests.');
    }

    $this->suffix = 'qp-it-'.bin2hex(random_bytes(4));
    $this->mounts = [];
    $this->policies = [];
    $this->authMounts = [];

    vaultItConfigure($this->vaultAddress, ['token' => $this->vaultRoot]);
});

afterEach(function () {
    if (! isset($this->suffix)) {
        return;
    }

    $admin = vaultItAdmin($this);

    foreach ($this->mounts as $mount) {
        $admin->delete("/v1/sys/mounts/{$mount}");
    }

    foreach ($this->authMounts as $mount) {
        $admin->delete("/v1/sys/auth/{$mount}");
    }

    foreach ($this->policies as $policy) {
        $admin->delete("/v1/sys/policies/acl/{$policy}");
    }

    $admin->delete("/v1/secret/metadata/{$this->suffix}");
});

/** @param  array<string, mixed>  $overrides */
function vaultItConfigure(string $address, array $overrides): void
{
    config([
        'queryproxy.secrets.cache_ttl' => 300,
        'queryproxy.secrets.timeout' => 5,

        'queryproxy.secrets.vault' => array_merge([
            'address' => $address, 'namespace' => '', 'auth' => 'token', 'token' => '',
            'role_id' => '', 'secret_id' => '', 'approle_mount' => 'approle',
            'kubernetes_role' => '', 'kubernetes_mount' => 'kubernetes',
            'kubernetes_jwt_path' => '/nonexistent', 'kv_version' => '2', 'mounts' => [], 'ca_file' => '',
        ], $overrides),
    ]);
    app(SecretResolver::class)->flush();
}

function vaultItAdmin(object $test): PendingRequest
{
    return Http::baseUrl($test->vaultAddress)->acceptJson()->withHeaders(['X-Vault-Token' => $test->vaultRoot]);
}

/** Store a KV v2 secret under the dev server's `secret/` mount. */
function vaultItWrite(object $test, string $path, array $data): void
{
    vaultItAdmin($test)->post("/v1/secret/data/{$test->suffix}/{$path}", ['data' => $data])->throw();
}

/** A connection whose team may use this run's paths (the dev server's secret/ mount and the KV v1 mount of the test). */
function vaultItConnection(object $test): Connection
{
    return SecretsWorld::connection(["vault://secret/{$test->suffix}", "vault://kv1-{$test->suffix}"]);
}

function vaultItFailure(Closure $callback): ?Failure
{
    try {
        $callback();
    } catch (Failure $e) {
        return $e;
    }

    return null;
}

test('a KV v2 secret resolves through token auth', function () {
    vaultItWrite($this, 'db', ['password' => 'it-p4ss-v2', 'other' => 'x']);

    $resolver = app(SecretResolver::class);

    expect($resolver->resolve("vault://secret/{$this->suffix}/db#password", vaultItConnection($this)))->toBe('it-p4ss-v2')
        ->and($resolver->resolve("vault://secret/{$this->suffix}/db#other", vaultItConnection($this)))->toBe('x');
});

test('a KV v1 secret resolves when the KV version is 1', function () {
    $mount = "kv1-{$this->suffix}";
    $this->mounts[] = $mount;
    vaultItAdmin($this)->post("/v1/sys/mounts/{$mount}", ['type' => 'kv', 'options' => ['version' => '1']])->throw();
    vaultItAdmin($this)->post("/v1/{$mount}/app/db", ['password' => 'it-p4ss-v1'])->throw();

    vaultItConfigure($this->vaultAddress, ['token' => $this->vaultRoot, 'kv_version' => '1', 'mounts' => [$mount]]);

    expect(app(SecretResolver::class)->resolve("vault://{$mount}/app/db#password", vaultItConnection($this)))->toBe('it-p4ss-v1');
});

test('AppRole login resolves what its policy allows and refuses the rest', function () {
    $policy = "pol-{$this->suffix}";
    $auth = "approle-{$this->suffix}";
    $this->policies[] = $policy;
    $this->authMounts[] = $auth;

    vaultItWrite($this, 'allowed/db', ['password' => 'it-p4ss-approle']);
    vaultItWrite($this, 'denied/db', ['password' => 'must-not-leak']);

    $admin = vaultItAdmin($this);
    $admin->put("/v1/sys/policies/acl/{$policy}", [
        'policy' => "path \"secret/data/{$this->suffix}/allowed/*\" { capabilities = [\"read\"] }",
    ])->throw();
    $admin->post("/v1/sys/auth/{$auth}", ['type' => 'approle'])->throw();
    $admin->post("/v1/auth/{$auth}/role/queryproxy", ['token_policies' => [$policy], 'token_ttl' => '10m'])->throw();

    $roleId = $admin->get("/v1/auth/{$auth}/role/queryproxy/role-id")->throw()->json('data.role_id');
    $secretId = $admin->post("/v1/auth/{$auth}/role/queryproxy/secret-id", ['ttl' => '10m'])->throw()->json('data.secret_id');

    vaultItConfigure($this->vaultAddress, ['auth' => 'approle', 'role_id' => $roleId, 'secret_id' => $secretId, 'approle_mount' => $auth]);

    $resolver = app(SecretResolver::class);

    expect($resolver->resolve("vault://secret/{$this->suffix}/allowed/db#password", vaultItConnection($this)))->toBe('it-p4ss-approle');

    $denied = vaultItFailure(fn () => $resolver->resolve("vault://secret/{$this->suffix}/denied/db#password", vaultItConnection($this)));

    expect($denied->reason)->toBe(Failure::ACCESS_DENIED)
        ->and($denied->getMessage())->not->toContain('must-not-leak');
});

test('AppRole login with a wrong secret id fails as an authentication error', function () {
    $auth = "approle-{$this->suffix}";
    $this->authMounts[] = $auth;

    $admin = vaultItAdmin($this);
    $admin->post("/v1/sys/auth/{$auth}", ['type' => 'approle'])->throw();
    $admin->post("/v1/auth/{$auth}/role/queryproxy", ['token_ttl' => '10m'])->throw();
    $roleId = $admin->get("/v1/auth/{$auth}/role/queryproxy/role-id")->throw()->json('data.role_id');

    vaultItConfigure($this->vaultAddress, ['auth' => 'approle', 'role_id' => $roleId, 'secret_id' => 'not-the-secret-id', 'approle_mount' => $auth]);

    $failure = vaultItFailure(fn () => app(SecretResolver::class)->resolve("vault://secret/{$this->suffix}/db#password", vaultItConnection($this)));

    expect($failure->reason)->toBe(Failure::AUTH_FAILED);
});

test('Vault refusals map to fixed reasons', function () {
    vaultItWrite($this, 'db', ['password' => 'x']);
    $resolver = app(SecretResolver::class);

    expect(vaultItFailure(fn () => $resolver->resolve("vault://secret/{$this->suffix}/nope#password", vaultItConnection($this)))->reason)->toBe(Failure::NOT_FOUND)
        ->and(vaultItFailure(fn () => $resolver->resolve("vault://secret/{$this->suffix}/db#absent", vaultItConnection($this)))->reason)->toBe(Failure::KEY_MISSING);

    vaultItConfigure($this->vaultAddress, ['token' => 'hvs.not-a-token']);

    $bad = vaultItFailure(fn () => app(SecretResolver::class)->resolve("vault://secret/{$this->suffix}/db#password", vaultItConnection($this)));

    expect($bad->reason)->toBe(Failure::ACCESS_DENIED)
        ->and($bad->getMessage())->not->toContain('hvs.not-a-token');
});

test('an unreachable Vault is reported as unavailable', function () {
    vaultItConfigure('http://127.0.0.1:1', ['token' => $this->vaultRoot]);

    $failure = vaultItFailure(fn () => app(SecretResolver::class)->resolve("vault://secret/{$this->suffix}/db#password", vaultItConnection($this)));

    expect($failure->reason)->toBe(Failure::UNAVAILABLE);
});

test('a reference outside the team prefixes is refused without calling Vault', function () {
    vaultItWrite($this, 'db', ['password' => 'must-not-leak']);

    $other = SecretsWorld::connection(['vault://secret/someone-else']);
    $failure = vaultItFailure(fn () => app(SecretResolver::class)->resolve("vault://secret/{$this->suffix}/db#password", $other));

    expect($failure->reason)->toBe(Failure::NOT_ALLOWED);
});

test('Vault API paths outside KV cannot be read, not even in KV v1 mode', function () {
    vaultItConfigure($this->vaultAddress, ['token' => $this->vaultRoot, 'kv_version' => '1', 'mounts' => ['secret']]);

    $connection = SecretsWorld::connection(['vault://secret', 'vault://auth']);
    $resolver = app(SecretResolver::class);

    // The root token's own id would come back from auth/token/lookup-self.
    expect(vaultItFailure(fn () => $resolver->resolve('vault://auth/token/lookup-self#id', $connection))->reason)->toBe(Failure::INVALID_REFERENCE);
});

test('the connection factory puts the Vault value into the driver config', function () {
    vaultItWrite($this, 'pg', ['password' => 'it-p4ss-config']);

    $connection = vaultItConnection($this);
    $connection->password = "vault://secret/{$this->suffix}/pg#password";

    expect(app(DynamicConnectionFactory::class)->configFor($connection)['password'])->toBe('it-p4ss-config');
});
