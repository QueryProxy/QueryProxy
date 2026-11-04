<?php

namespace App\Services\Secrets\Drivers;

use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolutionException as Failure;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\SecretsManager\SecretsManagerClient;
use Closure;
use Throwable;

/**
 * AWS Secrets Manager through the AWS SDK for PHP's default credential chain
 * (environment, shared files, web identity / IRSA, ECS task role, EC2 instance
 * profile).
 *
 * The SDK is used rather than a hand-written SigV4 client because it is already
 * a production dependency (league/flysystem-aws-s3-v3 requires it, so nothing
 * new ships) and re-implementing the credential chain, IRSA's STS exchange and
 * request signing here would be more code to get wrong than to reuse.
 *
 * Region: QUERYPROXY_AWS_REGION, else AWS_REGION / AWS_DEFAULT_REGION; a secret
 * given as a full ARN carries its own region, which wins.
 */
class AwsSecretsManagerDriver implements SecretDriver
{
    /** @var array<string, SecretsManagerClient> */
    private array $clients = [];

    /** @var (Closure(string): SecretsManagerClient)|null */
    private ?Closure $clientFactory = null;

    /** Replace how clients are built (tests hand in a client with a mock handler). */
    public function useClientFactory(?Closure $factory): static
    {
        $this->clientFactory = $factory;
        $this->clients = [];

        return $this;
    }

    public function scheme(): string
    {
        return SecretReference::AWS;
    }

    public function isConfigured(): bool
    {
        return (bool) config('queryproxy.secrets.aws.explicit', false);
    }

    public function diagnose(): array
    {
        $problems = [];
        $warnings = [];

        if ($this->region() === '') {
            $warnings[] = 'No AWS region is set (QUERYPROXY_AWS_REGION or AWS_REGION); only references that use a full secret ARN will resolve.';
        }

        $accessKey = $this->env('AWS_ACCESS_KEY_ID') !== '' && $this->env('AWS_SECRET_ACCESS_KEY') !== '';
        $webIdentity = $this->env('AWS_WEB_IDENTITY_TOKEN_FILE') !== '' && $this->env('AWS_ROLE_ARN') !== '';
        $container = $this->env('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI') !== '' || $this->env('AWS_CONTAINER_CREDENTIALS_FULL_URI') !== '';

        if ($this->env('AWS_ACCESS_KEY_ID') !== '' && $this->env('AWS_SECRET_ACCESS_KEY') === '') {
            $problems[] = 'AWS_ACCESS_KEY_ID is set without AWS_SECRET_ACCESS_KEY.';
        }

        if ($this->env('AWS_WEB_IDENTITY_TOKEN_FILE') !== '') {
            if (! is_readable($this->env('AWS_WEB_IDENTITY_TOKEN_FILE'))) {
                $problems[] = 'AWS_WEB_IDENTITY_TOKEN_FILE is not readable.';
            }

            if ($this->env('AWS_ROLE_ARN') === '') {
                $problems[] = 'AWS_WEB_IDENTITY_TOKEN_FILE is set without AWS_ROLE_ARN.';
            }
        }

        if (! $accessKey && ! $webIdentity && ! $container) {
            $warnings[] = 'No explicit AWS credential source in the environment; the shared credentials file and the instance metadata service will be tried.';
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    public function fetch(SecretReference $reference): string
    {
        $secretId = $reference->parts[0];
        $region = $this->regionFor($secretId);

        if ($region === '') {
            throw new Failure(Failure::NOT_CONFIGURED, $reference->scheme, $reference->location);
        }

        try {
            $result = $this->client($region)->getSecretValue(['SecretId' => $secretId]);
        } catch (AwsException $e) {
            throw $this->map($e, $reference);
        } catch (CredentialsException $e) {
            throw (new Failure(Failure::AUTH_FAILED, $reference->scheme, $reference->location))->causedBy($e);
        } catch (Throwable $e) {
            throw (new Failure(Failure::UNAVAILABLE, $reference->scheme, $reference->location))->causedBy($e);
        }

        $string = $result['SecretString'] ?? null;

        if (! is_string($string)) {
            // A binary secret has no SecretString.
            throw new Failure(Failure::MALFORMED, $reference->scheme, $reference->location);
        }

        if ($reference->key === null) {
            return $string;
        }

        $json = json_decode($string, true);

        if (! is_array($json)) {
            throw new Failure(Failure::MALFORMED, $reference->scheme, $reference->location);
        }

        if (! array_key_exists($reference->key, $json)) {
            throw new Failure(Failure::KEY_MISSING, $reference->scheme, $reference->location);
        }

        $value = $json[$reference->key];

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new Failure(Failure::MALFORMED, $reference->scheme, $reference->location);
        }

        return (string) $value;
    }

    private function map(AwsException $e, SecretReference $reference): Failure
    {
        $status = $e->getStatusCode();

        $reason = match ($e->getAwsErrorCode()) {
            'ResourceNotFoundException' => Failure::NOT_FOUND,
            'AccessDeniedException', 'AccessDenied' => Failure::ACCESS_DENIED,
            'UnrecognizedClientException', 'InvalidSignatureException', 'ExpiredTokenException', 'IncompleteSignature', 'MissingAuthenticationToken' => Failure::AUTH_FAILED,
            'DecryptionFailure', 'InvalidRequestException', 'InvalidParameterException' => Failure::MALFORMED,
            default => $e->getPrevious() instanceof CredentialsException ? Failure::AUTH_FAILED : Failure::UNAVAILABLE,
        };

        return new Failure($reason, $reference->scheme, $reference->location, $status);
    }

    private function client(string $region): SecretsManagerClient
    {
        return $this->clients[$region] ??= $this->clientFactory !== null
            ? ($this->clientFactory)($region)
            : new SecretsManagerClient(array_filter([
                'version' => '2017-10-17',
                'region' => $region,
                'endpoint' => trim((string) config('queryproxy.secrets.aws.endpoint', '')) ?: null,
                'retries' => 1,
                'http' => [
                    'timeout' => max(1, (int) config('queryproxy.secrets.timeout', 5)),
                    'connect_timeout' => max(1, (int) config('queryproxy.secrets.timeout', 5)),
                ],
            ], fn ($value) => $value !== null));
    }

    /** The region of an ARN (arn:aws:secretsmanager:<region>:...), else the configured one. */
    private function regionFor(string $secretId): string
    {
        if (preg_match('/^arn:aws[a-z-]*:secretsmanager:([a-z0-9-]+):/', $secretId, $match) === 1) {
            return $match[1];
        }

        return $this->region();
    }

    private function region(): string
    {
        return trim((string) config('queryproxy.secrets.aws.region', ''));
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
