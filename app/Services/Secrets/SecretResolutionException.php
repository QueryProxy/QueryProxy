<?php

namespace App\Services\Secrets;

use RuntimeException;

/**
 * An external secret reference could not be turned into a value (ADR-028).
 *
 * The message is fixed per reason and safe to show to users and to store: it
 * never carries a secret value, a token, a response body or the text of the
 * exception that caused it. The operator-facing detail (scheme, location,
 * HTTP status, class of the underlying error) is exposed as properties for the
 * log and the audit record; a previous exception is deliberately never chained,
 * because HTTP client exceptions quote the response body.
 */
class SecretResolutionException extends RuntimeException
{
    public const INVALID_REFERENCE = 'invalid_reference';

    public const NOT_ALLOWED = 'not_allowed';

    public const NOT_CONFIGURED = 'not_configured';

    public const AUTH_FAILED = 'auth_failed';

    public const ACCESS_DENIED = 'access_denied';

    public const NOT_FOUND = 'not_found';

    public const KEY_MISSING = 'key_missing';

    public const MALFORMED = 'malformed_response';

    public const UNAVAILABLE = 'unavailable';

    /** Reason code => the words used in the user-facing message. */
    private const LABELS = [
        self::INVALID_REFERENCE => 'invalid reference',
        self::NOT_ALLOWED => 'reference not allowed for this team',
        self::NOT_CONFIGURED => 'secret store not configured',
        self::AUTH_FAILED => 'authentication to the secret store failed',
        self::ACCESS_DENIED => 'access denied',
        self::NOT_FOUND => 'secret not found',
        self::KEY_MISSING => 'key not found in the secret',
        self::MALFORMED => 'unexpected response',
        self::UNAVAILABLE => 'secret store unavailable',
    ];

    public function __construct(
        public readonly string $reason,
        public readonly ?string $scheme = null,
        public readonly ?string $location = null,
        public readonly ?int $status = null,
        public readonly ?string $cause = null,
    ) {
        parent::__construct('The credentials could not be resolved from the external secret store ('.(self::LABELS[$reason] ?? 'error').').');
    }

    /** A failure caused by a thrown error: only its class is kept. */
    public function causedBy(\Throwable $e): static
    {
        return new static($this->reason, $this->scheme, $this->location, $this->status, $e::class);
    }

    public function forReference(SecretReference $reference): static
    {
        return new static($this->reason, $reference->scheme, $reference->location, $this->status, $this->cause);
    }
}
