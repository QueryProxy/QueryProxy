<?php

use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\QueryRequest;
use App\Models\Team;
use App\Services\Connections\DynamicConnectionFactory;
use App\Services\Doctor\Checks\SecretStoreCheck;
use App\Services\Doctor\CheckStatus;
use App\Services\Execution\QueryExecutor;
use App\Services\Secrets\SecretResolutionException;
use App\Services\Secrets\SecretResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\SecretsWorld;

const CONNECTION_SECRET_VALUE = 'pg-p4ss-SENTINEL-31c8';
const CONNECTION_SECRET_TOKEN = 'hvs.CONN-TOKEN-SENTINEL-77aa';
const CONNECTION_SECRET_REF = 'vault://secret/queryproxy/pg#password';

function connectionSecretVault(): void
{
    config([
        'queryproxy.secrets.cache_ttl' => 300,

        'queryproxy.secrets.vault' => [
            'address' => 'https://vault.test:8200', 'namespace' => '', 'auth' => 'token',
            'token' => CONNECTION_SECRET_TOKEN, 'role_id' => '', 'secret_id' => '',
            'approle_mount' => 'approle', 'kubernetes_role' => '', 'kubernetes_mount' => 'kubernetes',
            'kubernetes_jwt_path' => '/nonexistent', 'kv_version' => '2', 'ca_file' => '',
        ],
    ]);
}

/** @return list<string> every message and context the application logged during the test */
function connectionSecretLogCapture(): ArrayObject
{
    $lines = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($lines) {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    return $lines;
}

/** @param  list<string>|null  $prefixes  the team's reference prefixes (default: the one CONNECTION_SECRET_REF is under) */
function connectionSecretConnection(string $password = CONNECTION_SECRET_REF, array $attributes = [], ?array $prefixes = null): Connection
{
    $team = Team::factory()->create(['secret_reference_prefixes' => $prefixes ?? ['vault://secret/queryproxy']]);

    return Connection::factory()->create(array_merge(['team_id' => $team->id, 'password' => $password], $attributes));
}

function connectionSecretEverything(ArrayObject $logs): string
{
    return json_encode([
        'logs' => $logs->getArrayCopy(),
        'audit' => AuditLog::all()->map->toArray()->all(),
        'requests' => QueryRequest::all()->map->toArray()->all(),
    ]);
}

test('the factory hands the resolved password to the driver config', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => CONNECTION_SECRET_VALUE]]])]);
    $connection = connectionSecretConnection();

    $config = app(DynamicConnectionFactory::class)->configFor($connection);

    expect($config['password'])->toBe(CONNECTION_SECRET_VALUE);

    // The stored field still holds the reference, encrypted like any password.
    expect($connection->fresh()->password)->toBe(CONNECTION_SECRET_REF)
        ->and(DB::table('connections')->value('password'))->not->toContain('vault://');
});

test('the read-only identity resolves its own reference', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/v1/secret/data/queryproxy/ro' => Http::response(['data' => ['data' => ['password' => 'ro-pass']]])]);
    $connection = connectionSecretConnection('plain-main-pass', ['read_username' => 'ro', 'read_password' => 'vault://secret/queryproxy/ro#password']);

    $factory = app(DynamicConnectionFactory::class);

    expect($factory->configFor($connection)['password'])->toBe('plain-main-pass')
        ->and($factory->configFor($connection, readIdentity: true)['password'])->toBe('ro-pass');
});

test('a plain password is used as before and never touches a secret store', function () {
    Http::fake();
    $connection = connectionSecretConnection('plain-password');

    expect(app(DynamicConnectionFactory::class)->configFor($connection)['password'])->toBe('plain-password');

    Http::assertNothingSent();
});

test('a second configure inside the TTL does not go back to the store', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => CONNECTION_SECRET_VALUE]]])]);
    $connection = connectionSecretConnection();
    $factory = app(DynamicConnectionFactory::class);

    $factory->configure($connection);
    $factory->purge($connection);
    $factory->configure($connection);
    $factory->purge($connection);

    Http::assertSentCount(1);
});

test('a request on a connection whose secret cannot be read fails with a generic message and leaks nothing', function (int $status, string $reason) {
    connectionSecretVault();
    $logs = connectionSecretLogCapture();
    Http::fake(['vault.test:8200/*' => Http::response(['errors' => ['LEAK '.CONNECTION_SECRET_VALUE.' '.CONNECTION_SECRET_TOKEN]], $status)]);
    $connection = connectionSecretConnection();
    $request = QueryRequest::factory()->create([
        'team_id' => $connection->team_id,
        'connection_id' => $connection->id,
        'sql_original' => 'SELECT 1',
        'sql_prepared' => 'SELECT 1',
        'status' => QueryRequestStatus::Queued,
    ]);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toStartWith('The credentials could not be resolved from the external secret store');

    $audit = AuditLog::where('action', 'secret.resolve_failed')->sole();

    expect($audit->connection_id)->toBe($connection->id)
        ->and($audit->metadata)->toMatchArray([
            'field' => 'password',
            'scheme' => 'vault',
            'location' => 'secret/queryproxy/pg',
        ])
        ->and($audit->metadata['reason'])->toBe($reason)
        // The `#password` key and every secret-looking string stay out.
        ->and(AuditLog::where('action', 'request.execution_failed')->count())->toBe(1);

    $everything = connectionSecretEverything($logs);

    expect($everything)->not->toContain(CONNECTION_SECRET_VALUE)
        ->and($everything)->not->toContain(CONNECTION_SECRET_TOKEN)
        ->and($everything)->not->toContain('LEAK')
        ->and($logs->getArrayCopy())->toContain('A secret reference could not be resolved. '.json_encode([
            'connection_id' => $connection->id,
            'field' => 'password',
            'scheme' => 'vault',
            'location' => 'secret/queryproxy/pg',
            'reason' => $reason,
            'status' => $status,
        ]));
})->with([
    '403 is access denied' => [403, 'access_denied'],
    '404 is not found' => [404, 'not_found'],
    '503 is unavailable' => [503, 'unavailable'],
]);

test('a wrong path is rejected the same way', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response([], 404)]);
    $connection = connectionSecretConnection('vault://secret/typo/pg#password', prefixes: ['vault://secret']);

    $result = app(DynamicConnectionFactory::class)->test($connection);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('The credentials could not be resolved from the external secret store (secret not found).');
});

test('the connection test resolves the reference and reports a generic error on failure', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response(['errors' => ['LEAK']], 403)]);
    $connection = connectionSecretConnection();

    $result = app(DynamicConnectionFactory::class)->test($connection);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('The credentials could not be resolved from the external secret store (access denied).');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/secret/data/queryproxy/pg'));
});

test('a resolved password is scrubbed from a driver error message', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => CONNECTION_SECRET_VALUE]]])]);
    $connection = connectionSecretConnection();
    $factory = app(DynamicConnectionFactory::class);

    $factory->configure($connection);

    $redacted = $factory->redactError('login failed for password '.CONNECTION_SECRET_VALUE.' and '.CONNECTION_SECRET_REF, $connection);

    expect($redacted)->not->toContain(CONNECTION_SECRET_VALUE)
        ->and($redacted)->not->toContain('vault://');
});

test('a malformed reference is refused when the connection is saved', function () {
    $connection = connectionSecretConnection('plain');

    $update = fn () => $connection->update(['password' => 'vault://secret/only-mount']);

    expect($update)->toThrow(ValidationException::class);

    try {
        $update();
    } catch (ValidationException $e) {
        expect($e->errors()['password'][0])->toContain('vault://<mount>/<path>#<key>');
    }

    expect($connection->fresh()->password)->toBe('plain');

    expect(fn () => connectionSecretConnection('plain', ['read_password' => 'azure-kv://x']))
        ->toThrow(ValidationException::class);
});

test('saving other fields does not re-validate an untouched reference', function () {
    $connection = connectionSecretConnection();

    $connection->update(['name' => 'renamed']);

    expect($connection->fresh()->name)->toBe('renamed')
        ->and($connection->hasSecretReferencePassword())->toBeTrue()
        ->and($connection->hasSecretReferenceReadPassword())->toBeFalse();
});

test('a failing resolution is not hidden when the audit write fails', function () {
    connectionSecretVault();
    Http::fake(['vault.test:8200/*' => Http::response([], 403)]);
    $connection = connectionSecretConnection();
    // Break the audit table for this connection only.
    $connection->team_id = 999999;

    expect(fn () => app(SecretResolver::class)->resolve(CONNECTION_SECRET_REF, $connection))
        ->toThrow(SecretResolutionException::class);
});

test('the doctor check is quiet without references and without a configured store', function () {
    config(['queryproxy.secrets.vault.address' => '', 'queryproxy.secrets.azure.tenant_id' => '', 'queryproxy.secrets.azure.client_id' => '', 'queryproxy.secrets.azure.client_secret' => '', 'queryproxy.secrets.azure.federated_token_file' => '', 'queryproxy.secrets.aws.explicit' => false]);
    connectionSecretConnection('plain');

    $result = (new SecretStoreCheck)->run();

    expect($result->status)->toBe(CheckStatus::Skip);
});

test('the doctor check fails when a store in use is not configured', function () {
    config(['queryproxy.secrets.vault.address' => '']);
    connectionSecretConnection();

    $result = (new SecretStoreCheck)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('vault: QUERYPROXY_VAULT_ADDR is not set.');
});

test('the doctor check passes for a configured store and names a stored malformed reference', function () {
    connectionSecretVault();
    connectionSecretConnection();
    config(['queryproxy.secrets.aws.explicit' => false]);

    $ok = (new SecretStoreCheck)->run();

    expect($ok->status)->toBe(CheckStatus::Ok)
        ->and($ok->message)->toContain('vault (1 connection(s))');

    // A reference that was stored before the format check existed.
    DB::table('connections')->update(['password' => Crypt::encryptString('vault://broken')]);

    $broken = (new SecretStoreCheck)->run();

    expect($broken->status)->toBe(CheckStatus::Fail)
        ->and($broken->message)->toContain('malformed secret reference');
});

test('the doctor check flags a stored reference outside its team prefixes', function () {
    connectionSecretVault();
    $connection = connectionSecretConnection();
    SecretsWorld::withReference($connection, 'vault://secret/other-team/pg#password');

    $result = (new SecretStoreCheck)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('outside the team\'s secret reference prefixes');
});

test('a team cannot save a reference into another team\'s paths', function () {
    $teamA = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/a']]);
    Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/b']]);

    $create = fn (string $reference) => Connection::factory()->create(['team_id' => $teamA->id, 'password' => $reference]);

    expect(fn () => $create('vault://secret/teams/b/prod-db#password'))->toThrow(ValidationException::class);

    try {
        $create('vault://secret/teams/b/prod-db#password');
    } catch (ValidationException $e) {
        expect($e->errors()['password'][0])->toContain('outside the paths allowed for this team');
    }

    expect(Connection::count())->toBe(0)
        ->and($create('vault://secret/teams/a/prod-db#password')->hasSecretReferencePassword())->toBeTrue();
});

test('a team without prefixes cannot save any reference', function (?array $prefixes) {
    $team = Team::factory()->create(['secret_reference_prefixes' => $prefixes]);

    expect(fn () => Connection::factory()->create(['team_id' => $team->id, 'password' => CONNECTION_SECRET_REF]))
        ->toThrow(ValidationException::class);

    // Ordinary passwords are unaffected.
    expect(Connection::factory()->create(['team_id' => $team->id, 'password' => 'plain'])->exists)->toBeTrue();
})->with([[null], [[]]]);

test('the read-only password is bounded the same way', function () {
    $team = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/a']]);

    expect(fn () => Connection::factory()->create(['team_id' => $team->id, 'read_username' => 'ro', 'read_password' => 'vault://secret/teams/b/ro#password']))
        ->toThrow(ValidationException::class);
});

test('moving a connection to another team checks its reference again', function () {
    $connection = connectionSecretConnection();
    $other = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/other']]);

    expect(fn () => $connection->update(['team_id' => $other->id]))->toThrow(ValidationException::class)
        ->and($connection->fresh()->team_id)->not->toBe($other->id);
});

test('a stored reference that falls outside its team fails closed when it is resolved, without any request', function () {
    connectionSecretVault();
    $logs = connectionSecretLogCapture();
    Http::fake(['vault.test:8200/*' => Http::response(['data' => ['data' => ['password' => CONNECTION_SECRET_VALUE]]])]);

    // Team B's connection resolves its own secret first, which fills the cache.
    $teamB = connectionSecretConnection('vault://secret/teams/b/db#password', prefixes: ['vault://secret/teams/b']);
    expect(app(SecretResolver::class)->resolve('vault://secret/teams/b/db#password', $teamB))->toBe(CONNECTION_SECRET_VALUE);

    // Team A's connection holds team B's reference (written past the saving check, as an old row would be).
    $teamA = SecretsWorld::withReference(
        connectionSecretConnection('plain', prefixes: ['vault://secret/teams/a']),
        'vault://secret/teams/b/db#password',
    );
    $teamA->update(['host' => 'attacker.example']);

    $request = QueryRequest::factory()->create([
        'team_id' => $teamA->team_id,
        'connection_id' => $teamA->id,
        'sql_original' => 'SELECT 1',
        'sql_prepared' => 'SELECT 1',
        'status' => QueryRequestStatus::Queued,
    ]);

    app(QueryExecutor::class)->execute($request);

    $request->refresh();

    expect($request->status)->toBe(QueryRequestStatus::Failed)
        ->and($request->error_message)->toContain('reference not allowed for this team');

    $audit = AuditLog::where('action', 'secret.resolve_failed')->where('connection_id', $teamA->id)->sole();

    expect($audit->metadata['reason'])->toBe('not_allowed');

    // One request in total: team B's own. Team A never reached the store or the cache.
    Http::assertSentCount(1);

    expect(connectionSecretEverything($logs))->not->toContain(CONNECTION_SECRET_VALUE);

    // The connection test and the factory refuse it as well.
    $result = app(DynamicConnectionFactory::class)->test($teamA);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('reference not allowed for this team');
    Http::assertSentCount(1);
});
