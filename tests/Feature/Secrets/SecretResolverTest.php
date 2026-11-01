<?php

use App\Services\Secrets\Drivers\VaultDriver;
use App\Services\Secrets\SecretResolutionException as Failure;
use App\Services\Secrets\SecretResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecretsWorld;

const SECRET_TEST_VALUE = 's3cr3t-VALUE-9f2c';
const SECRET_TEST_TOKEN = 'hvs.TOKEN-7d1a';

function secretTestVault(array $overrides = []): void
{
    config([
        'queryproxy.secrets.cache_ttl' => 300,

        'queryproxy.secrets.vault' => array_merge([
            'address' => 'https://vault.test:8200',
            'namespace' => '',
            'auth' => 'token',
            'token' => SECRET_TEST_TOKEN,
            'role_id' => '',
            'secret_id' => '',
            'approle_mount' => 'approle',
            'kubernetes_role' => '',
            'kubernetes_mount' => 'kubernetes',
            'kubernetes_jwt_path' => '/nonexistent',
            'kv_version' => '2',
            'mounts' => [],
            'ca_file' => '',
        ], $overrides),
    ]);
}

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

test('a vault kv v2 reference resolves with the token header', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/v1/secret/data/queryproxy/db' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $value = app(SecretResolver::class)->resolve('vault://secret/queryproxy/db#password', SecretsWorld::connection());

    expect($value)->toBe(SECRET_TEST_VALUE);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://vault.test:8200/v1/secret/data/queryproxy/db'
        && $request->header('X-Vault-Token') === [SECRET_TEST_TOKEN]);
});

test('a vault kv v1 reference reads the path without data/', function () {
    secretTestVault(['kv_version' => '1', 'namespace' => 'team-a', 'mounts' => ['kv']]);
    Http::fake(['vault.test:8200/v1/kv/queryproxy/db' => Http::response(['data' => ['password' => SECRET_TEST_VALUE]])]);

    expect(app(SecretResolver::class)->resolve('vault://kv/queryproxy/db#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE);

    Http::assertSent(fn (Request $request) => $request->header('X-Vault-Namespace') === ['team-a']);
});

test('approle logs in once and reuses the client token', function () {
    secretTestVault(['auth' => 'approle', 'token' => '', 'role_id' => 'role-1', 'secret_id' => 'sid-1']);
    Http::fake([
        'vault.test:8200/v1/auth/approle/login' => Http::response(['auth' => ['client_token' => 'hvs.login-token', 'lease_duration' => 3600]]),
        'vault.test:8200/v1/secret/data/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE, 'other' => 'x']]]),
    ]);

    $resolver = app(SecretResolver::class);
    $resolver->resolve('vault://secret/a#password', SecretsWorld::connection());
    $resolver->resolve('vault://secret/b#other', SecretsWorld::connection());

    Http::assertSentCount(3); // one login, two reads
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/auth/approle/login')
        && $request->data() === ['role_id' => 'role-1', 'secret_id' => 'sid-1']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/data/b') && $request->header('X-Vault-Token') === ['hvs.login-token']);
});

test('kubernetes auth sends the service account token', function () {
    $jwt = tempnam(sys_get_temp_dir(), 'qp_jwt_');
    file_put_contents($jwt, "sa-jwt-token\n");
    secretTestVault(['auth' => 'kubernetes', 'token' => '', 'kubernetes_role' => 'queryproxy', 'kubernetes_jwt_path' => $jwt]);
    Http::fake([
        'vault.test:8200/v1/auth/kubernetes/login' => Http::response(['auth' => ['client_token' => 'hvs.k8s', 'lease_duration' => 60]]),
        'vault.test:8200/v1/secret/data/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]]),
    ]);

    expect(app(SecretResolver::class)->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/auth/kubernetes/login')
        && $request->data() === ['role' => 'queryproxy', 'jwt' => 'sa-jwt-token']);

    @unlink($jwt);
});

test('a login token that is refused is replaced by one fresh login', function () {
    secretTestVault(['auth' => 'approle', 'token' => '', 'role_id' => 'r', 'secret_id' => 's']);
    Http::fake([
        'vault.test:8200/v1/auth/approle/login' => Http::sequence()
            ->push(['auth' => ['client_token' => 'hvs.old', 'lease_duration' => 3600]])
            ->push(['auth' => ['client_token' => 'hvs.new', 'lease_duration' => 3600]]),
        'vault.test:8200/v1/secret/data/*' => Http::sequence()
            ->push(['errors' => ['permission denied']], 403)
            ->push(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]]),
    ]);

    expect(app(SecretResolver::class)->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE);
});

test('store problems end in a failure with a fixed reason and no store text', function (array $response, string $reason) {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(...$response)]);

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('vault://secret/queryproxy/db#password', SecretsWorld::connection()));

    expect($failure)->toBeInstanceOf(Failure::class)
        ->and($failure->reason)->toBe($reason)
        ->and($failure->scheme)->toBe('vault')
        ->and($failure->location)->toBe('secret/queryproxy/db')
        ->and($failure->getMessage())->not->toContain('LEAK');
})->with([
    'denied' => [[['errors' => ['LEAK permission denied']], 403], Failure::ACCESS_DENIED],
    'missing path' => [[['errors' => []], 404], Failure::NOT_FOUND],
    'server error' => [[['errors' => ['LEAK boom']], 503], Failure::UNAVAILABLE],
    'missing key' => [[['data' => ['data' => ['other' => 'LEAK']]]], Failure::KEY_MISSING],
    'nested value' => [[['data' => ['data' => ['password' => ['LEAK']]]]], Failure::MALFORMED],
    'not json' => [['LEAK html page'], Failure::MALFORMED],
    'empty value' => [[['data' => ['data' => ['password' => '']]]], Failure::KEY_MISSING],
]);

test('an unreachable store is reported as unavailable with only the exception class', function () {
    secretTestVault();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: connect to vault.test with token '.SECRET_TEST_TOKEN));

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('vault://secret/queryproxy/db#password', SecretsWorld::connection()));

    expect($failure->reason)->toBe(Failure::UNAVAILABLE)
        ->and($failure->cause)->toBe(ConnectionException::class)
        ->and($failure->getMessage())->not->toContain(SECRET_TEST_TOKEN)
        ->and($failure->getPrevious())->toBeNull();
});

test('a missing store configuration fails closed without any request', function () {
    secretTestVault(['address' => '']);
    Http::fake();

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('vault://secret/a#password', SecretsWorld::connection()));

    expect($failure->reason)->toBe(Failure::NOT_CONFIGURED);
    Http::assertNothingSent();
});

test('the second resolution inside the TTL does not go to the network', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $resolver = app(SecretResolver::class);

    expect($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE)
        ->and($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE)
        ->and($resolver->peek('vault://secret/a#password'))->toBe(SECRET_TEST_VALUE);

    Http::assertSentCount(1);
});

test('the value is fetched again once the TTL has passed', function () {
    secretTestVault();
    config(['queryproxy.secrets.cache_ttl' => 60]);
    Http::fake(['vault.test:8200/*' => Http::sequence()
        ->push(['data' => ['data' => ['password' => 'first']]])
        ->push(['data' => ['data' => ['password' => 'rotated']]])]);

    $resolver = app(SecretResolver::class);

    expect($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe('first');

    $this->travel(59)->seconds();
    expect($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe('first');

    $this->travel(2)->seconds();
    expect($resolver->peek('vault://secret/a#password'))->toBeNull()
        ->and($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe('rotated');

    Http::assertSentCount(2);
});

test('a TTL of zero turns the cache off', function () {
    secretTestVault();
    config(['queryproxy.secrets.cache_ttl' => 0]);
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $resolver = app(SecretResolver::class);
    $resolver->resolve('vault://secret/a#password', SecretsWorld::connection());
    $resolver->resolve('vault://secret/a#password', SecretsWorld::connection());

    Http::assertSentCount(2);
});

test('the resolved value never reaches the Laravel cache store', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);
    Cache::spy();

    app(SecretResolver::class)->resolve('vault://secret/a#password', SecretsWorld::connection());

    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('forever');
});

test('a failure is not cached', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::sequence()
        ->push([], 503)
        ->push(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $resolver = app(SecretResolver::class);

    expect(secretTestFailure(fn () => $resolver->resolve('vault://secret/a#password', SecretsWorld::connection())))->not->toBeNull()
        ->and($resolver->resolve('vault://secret/a#password', SecretsWorld::connection()))->toBe(SECRET_TEST_VALUE);
});

test('a reference outside the connection team prefixes is refused before any request', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $team = SecretsWorld::connection(['vault://secret/teams/a']);
    $resolver = app(SecretResolver::class);

    $failure = secretTestFailure(fn () => $resolver->resolve('vault://secret/teams/b/db#password', $team));

    expect($failure->reason)->toBe(Failure::NOT_ALLOWED);
    Http::assertNothingSent();

    expect($resolver->resolve('vault://secret/teams/a/db#password', $team))->toBe(SECRET_TEST_VALUE);
});

test('a team without prefixes cannot resolve any reference', function () {
    secretTestVault();
    Http::fake();

    $resolver = app(SecretResolver::class);
    $orphan = SecretsWorld::connection();
    $orphan->setRelation('team', null);

    foreach ([SecretsWorld::connection([]), $orphan] as $connection) {
        $failure = secretTestFailure(fn () => $resolver->resolve('vault://secret/a#password', $connection));

        expect($failure->reason)->toBe(Failure::NOT_ALLOWED);
    }

    Http::assertNothingSent();
});

test('a value another team resolved is not served from the cache to a team that may not read it', function () {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $resolver = app(SecretResolver::class);
    $reference = 'vault://secret/teams/b/db#password';

    expect($resolver->resolve($reference, SecretsWorld::connection(['vault://secret/teams/b'])))->toBe(SECRET_TEST_VALUE);

    $failure = secretTestFailure(fn () => $resolver->resolve($reference, SecretsWorld::connection(['vault://secret/teams/a'])));

    expect($failure->reason)->toBe(Failure::NOT_ALLOWED);
    Http::assertSentCount(1);
});

test('prefix matching is on normalized references and whole segments', function (string $reference, bool $allowed) {
    secretTestVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $connection = SecretsWorld::connection(['vault://secret/teams/a/']);
    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve($reference, $connection));

    if ($allowed) {
        expect($failure)->toBeNull();
    } else {
        expect($failure)->not->toBeNull();
        Http::assertNothingSent();
    }
})->with([
    'inside' => ['vault://secret/teams/a/db#password', true],
    'scheme in capitals' => ['VAULT://secret/teams/a/db#password', true],
    'surrounding blanks' => ['  vault://secret/teams/a/db#password ', true],
    'sibling with the same start' => ['vault://secret/teams/ab/db#password', false],
    'parent' => ['vault://secret/teams#password', false],
    'dot dot' => ['vault://secret/teams/a/../b/db#password', false],
    'encoded dot dot' => ['vault://secret/teams/a/%2e%2e/b/db#password', false],
    'encoded slash' => ['vault://secret/teams/a%2f..%2fb/db#password', false],
    'empty segment' => ['vault://secret/teams//b/db#password', false],
    'other scheme, same path' => ['aws-sm://secret/teams/a/db#password', false],
    'other mount' => ['vault://kv/teams/a/db#password', false],
]);

test('only KV mounts can be read', function (string $reference) {
    secretTestVault(['kv_version' => '1', 'mounts' => ['secret']]);
    Http::fake();

    $connection = SecretsWorld::connection(['vault://secret', 'vault://auth', 'vault://sys']);
    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve($reference, $connection));

    expect($failure->reason)->toBeIn([Failure::INVALID_REFERENCE, Failure::NOT_ALLOWED]);
    Http::assertNothingSent();
})->with([
    'own token' => ['vault://auth/token/lookup-self#id'],
    'sys' => ['vault://sys/internal/ui/mounts#data'],
    'identity' => ['vault://identity/entity/id#id'],
    'cubbyhole' => ['vault://cubbyhole/x#y'],
    'reserved in capitals' => ['vault://AUTH/token/lookup-self#id'],
]);

test('KV v1 needs the mounts list and then reads only those', function () {
    secretTestVault(['kv_version' => '1']);
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['password' => SECRET_TEST_VALUE]])]);

    $connection = SecretsWorld::connection(['vault://secret', 'vault://kv']);
    $resolver = app(SecretResolver::class);

    expect(secretTestFailure(fn () => $resolver->resolve('vault://secret/app/db#password', $connection))->reason)->toBe(Failure::NOT_CONFIGURED);
    Http::assertNothingSent();

    secretTestVault(['kv_version' => '1', 'mounts' => ['secret']]);

    expect($resolver->resolve('vault://secret/app/db#password', $connection))->toBe(SECRET_TEST_VALUE);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://vault.test:8200/v1/secret/app/db');

    // A mount that is not listed is not read, even though the team may use its prefix.
    expect(secretTestFailure(fn () => $resolver->resolve('vault://kv/app/db#password', $connection))->reason)->toBe(Failure::NOT_CONFIGURED);
    Http::assertSentCount(1);
});

test('a mount with a slash in its path is split at the listed mount', function () {
    secretTestVault(['mounts' => ['kv/prod', 'kv']]);
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => SECRET_TEST_VALUE]]])]);

    $connection = SecretsWorld::connection(['vault://kv']);

    expect(app(SecretResolver::class)->resolve('vault://kv/prod/app/db#password', $connection))->toBe(SECRET_TEST_VALUE);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://vault.test:8200/v1/kv/prod/data/app/db');
});

test('vault diagnosis requires mounts for KV v1 and refuses reserved ones', function () {
    secretTestVault(['kv_version' => '1']);

    expect(implode(' ', app(VaultDriver::class)->diagnose()['problems']))->toContain('QUERYPROXY_VAULT_MOUNTS is required with KV v1');

    secretTestVault(['kv_version' => '1', 'mounts' => ['secret', 'sys/internal']]);

    expect(implode(' ', app(VaultDriver::class)->diagnose()['problems']))->toContain('"sys/internal", which is not a KV mount');
});

test('an invalid reference fails closed', function () {
    secretTestVault();
    Http::fake();

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('vault://secret/only-mount', SecretsWorld::connection()));

    expect($failure->reason)->toBe(Failure::INVALID_REFERENCE);
    Http::assertNothingSent();
});

test('vault diagnosis names what is missing', function () {
    secretTestVault(['address' => '', 'token' => '']);

    $driver = app(VaultDriver::class);

    expect($driver->isConfigured())->toBeFalse()
        ->and($driver->diagnose()['problems'])->toContain('QUERYPROXY_VAULT_ADDR is not set.');

    secretTestVault(['address' => 'http://vault.corp:8200', 'auth' => 'approle', 'token' => '']);

    $diagnosis = app(VaultDriver::class)->diagnose();

    expect(app(VaultDriver::class)->isConfigured())->toBeTrue()
        ->and(implode(' ', $diagnosis['problems']))->toContain('AppRole login needs')
        ->and(implode(' ', $diagnosis['warnings']))->toContain('plain http');
});
