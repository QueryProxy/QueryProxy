<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException;
use App\Services\Secrets\TeamSecretPrefixes;
use Illuminate\Validation\ValidationException;

test('prefixes are normalized: trailing slash, scheme case, azure case', function () {
    expect(SecretReference::normalizePrefix('vault://secret/teams/payments/'))->toBe('vault://secret/teams/payments')
        ->and(SecretReference::normalizePrefix(' VAULT://secret/teams/payments '))->toBe('vault://secret/teams/payments')
        ->and(SecretReference::normalizePrefix('azure-kv://Corp-Vault/Team-A'))->toBe('azure-kv://corp-vault/team-a')
        ->and(SecretReference::normalizePrefix('aws-sm://prod/payments/'))->toBe('aws-sm://prod/payments');
});

test('a prefix that could widen or escape is refused', function (string $prefix) {
    expect(fn () => SecretReference::normalizePrefix($prefix))
        ->toThrow(SecretResolutionException::class);
})->with([
    'no scheme' => 'secret/teams/payments',
    'only the scheme' => 'vault://',
    'wildcard' => 'vault://secret/teams/*',
    'key' => 'vault://secret/teams/payments#password',
    'dot dot' => 'vault://secret/teams/../other',
    'dot' => 'vault://secret/./teams',
    'empty segment' => 'vault://secret//teams',
    'encoded' => 'vault://secret/teams%2fother',
    'query' => 'vault://secret/teams?x=1',
    'blank' => 'vault://secret/te ams',
    'reserved mount' => 'vault://auth/token',
    'too long' => 'vault://secret/'.'aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa/aaaaaaaaaa',
]);

test('a prefix covers its own path and what is below it, never a sibling', function () {
    $reference = fn (string $value) => SecretReference::parse($value);
    $prefixes = ['vault://secret/teams/pay'];

    expect($reference('vault://secret/teams/pay/db#p')->fallsUnder($prefixes))->toBeTrue()
        ->and($reference('vault://secret/teams/pay/a/b#p')->fallsUnder($prefixes))->toBeTrue()
        ->and($reference('vault://secret/teams/payments/db#p')->fallsUnder($prefixes))->toBeFalse()
        ->and($reference('vault://secret/teams#p')->fallsUnder($prefixes))->toBeFalse()
        ->and($reference('vault://secret/teams/pay/db#p')->fallsUnder([]))->toBeFalse()
        ->and($reference('azure-kv://CORP-vault/db')->fallsUnder(['azure-kv://corp-vault']))->toBeTrue();
});

test('the service stores the normalized list and audits the change', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();

    $stored = app(TeamSecretPrefixes::class)->set($team, [
        'vault://secret/teams/payments/', 'VAULT://secret/teams/payments', '', 'aws-sm://prod/payments',
    ], $admin);

    expect($stored)->toBe(['vault://secret/teams/payments', 'aws-sm://prod/payments'])
        ->and($team->fresh()->secretReferencePrefixes())->toBe($stored);

    $audit = AuditLog::where('action', 'team.secret_prefixes_changed')->sole();

    expect($audit->team_id)->toBe($team->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->metadata)->toBe(['before' => [], 'after' => $stored]);

    // Setting the same list again records nothing new.
    app(TeamSecretPrefixes::class)->set($team->fresh(), $stored, $admin);

    expect(AuditLog::where('action', 'team.secret_prefixes_changed')->count())->toBe(1);

    // An empty list takes the permission away.
    app(TeamSecretPrefixes::class)->set($team->fresh(), [], $admin);

    expect($team->fresh()->secretReferencePrefixes())->toBe([])
        ->and($team->fresh()->secret_reference_prefixes)->toBeNull();
});

test('the service refuses an invalid prefix and changes nothing', function () {
    $team = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/teams/a']]);

    expect(fn () => app(TeamSecretPrefixes::class)->set($team, ['vault://secret/teams/*']))
        ->toThrow(ValidationException::class);

    expect(fn () => app(TeamSecretPrefixes::class)->set($team, array_map(fn ($i) => "vault://secret/t{$i}", range(1, TeamSecretPrefixes::MAX + 1))))
        ->toThrow(ValidationException::class);

    expect($team->fresh()->secretReferencePrefixes())->toBe(['vault://secret/teams/a'])
        ->and(AuditLog::where('action', 'team.secret_prefixes_changed')->count())->toBe(0);
});

test('a stored entry that does not normalize is ignored, never trusted', function () {
    $team = Team::factory()->create(['secret_reference_prefixes' => ['vault://secret/ok', 'vault://secret/*', 'garbage', 'vault://secret/../x']]);

    expect($team->secretReferencePrefixes())->toBe(['vault://secret/ok']);
});

test('the artisan command shows, sets and clears the prefixes', function () {
    $team = Team::factory()->create(['slug' => 'payments']);

    $this->artisan('queryproxy:team:secret-prefixes', ['team' => 'payments'])
        ->expectsOutputToContain('cannot use external secret references')
        ->assertSuccessful();

    $this->artisan('queryproxy:team:secret-prefixes', ['team' => (string) $team->id, 'prefixes' => ['vault://secret/teams/payments/']])
        ->expectsOutputToContain('vault://secret/teams/payments')
        ->assertSuccessful();

    expect($team->fresh()->secretReferencePrefixes())->toBe(['vault://secret/teams/payments']);

    $this->artisan('queryproxy:team:secret-prefixes', ['team' => 'payments', 'prefixes' => ['vault://secret/*']])
        ->expectsOutputToContain('Invalid prefix')
        ->assertFailed();

    $this->artisan('queryproxy:team:secret-prefixes', ['team' => 'payments', '--clear' => true])->assertSuccessful();

    expect($team->fresh()->secretReferencePrefixes())->toBe([]);

    $this->artisan('queryproxy:team:secret-prefixes', ['team' => 'nope'])->assertFailed();
});
