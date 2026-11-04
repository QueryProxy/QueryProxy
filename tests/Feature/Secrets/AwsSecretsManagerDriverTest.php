<?php

use App\Services\Secrets\Drivers\AwsSecretsManagerDriver;
use App\Services\Secrets\SecretResolutionException as Failure;
use App\Services\Secrets\SecretResolver;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use GuzzleHttp\Psr7\Response;
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

/**
 * Wire an AWS driver whose client answers from $mock instead of the network.
 *
 * @param  list<string>  $regions  filled with the region each client was built for
 */
function awsTestDriver(MockHandler $mock, array &$regions = []): void
{
    config(['queryproxy.secrets.cache_ttl' => 300, 'queryproxy.secrets.aws.region' => 'eu-central-1']);

    $driver = (new AwsSecretsManagerDriver)->useClientFactory(function (string $region) use ($mock, &$regions) {
        $regions[] = $region;

        return new SecretsManagerClient([
            'version' => '2017-10-17',
            'region' => $region,
            'credentials' => ['key' => 'AKIATEST', 'secret' => 'test-secret'],
            'handler' => $mock,
        ]);
    });

    app()->instance(AwsSecretsManagerDriver::class, $driver);
}

function awsTestError(string $code, int $status): AwsException
{
    return new AwsException('LEAK raw SDK message', new Command('GetSecretValue'), ['code' => $code, 'response' => new Response($status)]);
}

test('a plain-string secret resolves as is', function () {
    awsTestDriver($mock = new MockHandler([new Result(['SecretString' => 's3cr3t-plain'])]));

    expect(app(SecretResolver::class)->resolve('aws-sm://prod/queryproxy/db', SecretsWorld::connection()))->toBe('s3cr3t-plain');
    expect($mock->count())->toBe(0);
});

test('a json secret resolves through the key after the hash', function () {
    awsTestDriver(new MockHandler([new Result(['SecretString' => '{"username":"app","password":"s3cr3t-json"}'])]));

    expect(app(SecretResolver::class)->resolve('aws-sm://prod/queryproxy/db#password', SecretsWorld::connection()))->toBe('s3cr3t-json');
});

test('an ARN carries its own region', function () {
    $regions = [];
    awsTestDriver(new MockHandler([new Result(['SecretString' => 'v'])]), $regions);

    app(SecretResolver::class)->resolve('aws-sm://arn:aws:secretsmanager:eu-west-1:123456789012:secret:prod/db-AbCdEf', SecretsWorld::connection());

    expect($regions)->toBe(['eu-west-1']);
});

test('AWS failures map to fixed reasons', function (Closure $answer, string $reason) {
    awsTestDriver(new MockHandler([$answer()]));

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('aws-sm://prod/db#password', SecretsWorld::connection()));

    expect($failure->reason)->toBe($reason)
        ->and($failure->getMessage())->not->toContain('LEAK');
})->with([
    'not found' => [fn () => awsTestError('ResourceNotFoundException', 400), Failure::NOT_FOUND],
    'denied' => [fn () => awsTestError('AccessDeniedException', 400), Failure::ACCESS_DENIED],
    'bad credentials' => [fn () => awsTestError('UnrecognizedClientException', 400), Failure::AUTH_FAILED],
    'no credentials' => [fn () => new CredentialsException('LEAK no credentials'), Failure::AUTH_FAILED],
    'throttled' => [fn () => awsTestError('ThrottlingException', 400), Failure::UNAVAILABLE],
    'binary secret' => [fn () => new Result(['SecretBinary' => 'x']), Failure::MALFORMED],
    'not json' => [fn () => new Result(['SecretString' => 'LEAK plain']), Failure::MALFORMED],
    'key absent' => [fn () => new Result(['SecretString' => '{"user":"a"}']), Failure::KEY_MISSING],
]);

test('a name without any region fails closed', function () {
    awsTestDriver(new MockHandler([]));
    config(['queryproxy.secrets.aws.region' => '']);

    $failure = secretTestFailure(fn () => app(SecretResolver::class)->resolve('aws-sm://prod/db#password', SecretsWorld::connection()));

    expect($failure->reason)->toBe(Failure::NOT_CONFIGURED);
});

test('the second resolution inside the TTL does not call AWS', function () {
    awsTestDriver($mock = new MockHandler([new Result(['SecretString' => 'only-once'])]));

    $resolver = app(SecretResolver::class);

    expect($resolver->resolve('aws-sm://prod/db', SecretsWorld::connection()))->toBe('only-once')
        ->and($resolver->resolve('aws-sm://prod/db', SecretsWorld::connection()))->toBe('only-once')
        ->and($mock->count())->toBe(0);
});

test('aws diagnosis warns about a missing region and a half-set key pair', function () {
    config(['queryproxy.secrets.aws.region' => '']);

    $diagnosis = (new AwsSecretsManagerDriver)->diagnose();

    expect(implode(' ', $diagnosis['warnings']))->toContain('No AWS region');
});
