<?php

use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException;

test('a vault reference is parsed into mount, path and key', function () {
    $reference = SecretReference::parse('vault://secret/queryproxy/prod/db#password');

    expect($reference->scheme)->toBe('vault')
        ->and($reference->parts)->toBe(['secret', 'queryproxy', 'prod', 'db'])
        ->and($reference->key)->toBe('password')
        ->and($reference->location)->toBe('secret/queryproxy/prod/db')
        ->and($reference->address())->toBe('vault://secret/queryproxy/prod/db');
});

test('an aws reference takes a name or an ARN and an optional json key', function () {
    $named = SecretReference::parse('aws-sm://prod/queryproxy/db#password');
    $arn = SecretReference::parse('aws-sm://arn:aws:secretsmanager:eu-west-1:123456789012:secret:prod/db-AbCdEf');

    expect($named->parts)->toBe(['prod/queryproxy/db'])
        ->and($named->key)->toBe('password')
        ->and($arn->parts)->toBe(['arn:aws:secretsmanager:eu-west-1:123456789012:secret:prod/db-AbCdEf'])
        ->and($arn->key)->toBeNull();
});

test('an azure reference takes a vault, a secret and an optional version', function () {
    $latest = SecretReference::parse('azure-kv://corp-vault/db-password');
    $pinned = SecretReference::parse('azure-kv://corp-vault/db-password/0123456789abcdef0123456789abcdef');

    expect($latest->parts)->toBe(['corp-vault', 'db-password'])
        ->and($pinned->parts[2])->toBe('0123456789abcdef0123456789abcdef');
});

test('malformed references are rejected', function (string $value) {
    expect(fn () => SecretReference::parse($value))
        ->toThrow(SecretResolutionException::class);

    expect(SecretReference::validationError($value))->toStartWith('Invalid secret reference. Expected ');
})->with([
    'vault without key' => 'vault://secret/app/db',
    'vault without path' => 'vault://secret#password',
    'vault empty key' => 'vault://secret/app/db#',
    'vault dot segment' => 'vault://secret/../sys/db#password',
    'vault empty segment' => 'vault://secret//db#password',
    'vault query string' => 'vault://secret/app/db?version=2#password',
    'vault whitespace' => 'vault://secret/app db#password',
    'aws empty id' => 'aws-sm://',
    'aws bad character' => 'aws-sm://prod/db$',
    'azure missing secret' => 'azure-kv://corp-vault',
    'azure bad vault name' => 'azure-kv://x/db-password',
    'azure key part' => 'azure-kv://corp-vault/db-password#key',
    'azure extra segment' => 'azure-kv://corp-vault/db-password/abc/def',
    'azure dotted secret' => 'azure-kv://corp-vault/db.password',
]);

test('anything that is not a reference passes through untouched', function (?string $value) {
    expect(SecretReference::looksLikeReference($value))->toBeFalse()
        ->and(SecretReference::validationError($value))->toBeNull();
})->with([
    'plain password' => 'correct horse battery staple',
    'empty' => '',
    'null' => null,
    'other scheme' => 'https://vault.example.com/x',
    'scheme in the middle' => 'my-vault://secret/x#y',
]);

test('the scheme is detected without regard to case but the reference must still parse', function () {
    expect(SecretReference::looksLikeReference('VAULT://secret/app/db#password'))->toBeTrue()
        ->and(SecretReference::validationError('VAULT://secret/app/db#password'))->toBeNull();
});
