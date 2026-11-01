<?php

namespace App\Services\Secrets;

/**
 * A parsed external secret reference (ADR-028):
 *
 *   vault://<mount>/<path>#<key>
 *   aws-sm://<secret-id>[#<json-key>]
 *   azure-kv://<vault-name>/<secret-name>[/<version>]
 *
 * A reference is only a pointer: it carries no secret, so it may be stored,
 * logged and audited (the `#key` part is left out of the location on purpose,
 * the location is what logs and audit records show).
 */
final class SecretReference
{
    public const VAULT = 'vault';

    public const AWS = 'aws-sm';

    public const AZURE = 'azure-kv';

    private const SEGMENT = '/^[A-Za-z0-9._~@=+-]+$/';

    private const KEY = '/^[A-Za-z0-9_.:@-]{1,255}$/';

    /**
     * Vault mounts that are not secrets engines to read from: API areas such
     * as the token, policy and identity endpoints. A KV reference must never
     * address them (`vault://auth/token/lookup-self#id` would return the
     * instance's own token).
     */
    public const VAULT_RESERVED_MOUNTS = ['auth', 'sys', 'identity', 'cubbyhole'];

    /**
     * @param  list<string>  $parts  vault: [mount, ...path segments]; aws-sm: [secret-id]; azure-kv: [vault, secret, version?]
     */
    private function __construct(
        public readonly string $scheme,
        public readonly string $location,
        public readonly array $parts,
        public readonly ?string $key,
        public readonly string $raw,
    ) {}

    /** @return list<string> */
    public static function schemes(): array
    {
        return [self::VAULT, self::AWS, self::AZURE];
    }

    /** Does the value start like a reference of one of our schemes? (Syntax is not checked here.) */
    public static function looksLikeReference(?string $value): bool
    {
        return self::schemeOf($value) !== null;
    }

    public static function schemeOf(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        foreach (self::schemes() as $scheme) {
            if (stripos(ltrim($value), $scheme.'://') === 0) {
                return $scheme;
            }
        }

        return null;
    }

    /** Null when $value is not a reference or is a valid one; otherwise a message that names the expected form. */
    public static function validationError(?string $value): ?string
    {
        $scheme = self::schemeOf($value);

        if ($scheme === null) {
            return null;
        }

        try {
            self::parse((string) $value);

            return null;
        } catch (SecretResolutionException) {
            return 'Invalid secret reference. Expected '.match ($scheme) {
                self::VAULT => 'vault://<mount>/<path>#<key>',
                self::AWS => 'aws-sm://<secret-id>[#<json-key>]',
                self::AZURE => 'azure-kv://<vault-name>/<secret-name>[/<version>]',
            }.'.';
        }
    }

    /** @throws SecretResolutionException with reason invalid_reference */
    public static function parse(string $raw): self
    {
        $value = trim($raw);
        $scheme = self::schemeOf($value);

        if ($scheme === null || preg_match('/[\x00-\x20\x7f?]/', $value) === 1) {
            throw new SecretResolutionException(SecretResolutionException::INVALID_REFERENCE, $scheme);
        }

        $rest = substr($value, strlen($scheme) + 3);
        $hash = strpos($rest, '#');
        $key = $hash === false ? null : substr($rest, $hash + 1);
        $body = $hash === false ? $rest : substr($rest, 0, $hash);

        $fail = fn () => new SecretResolutionException(SecretResolutionException::INVALID_REFERENCE, $scheme);

        if ($key !== null && preg_match(self::KEY, $key) !== 1) {
            throw $fail();
        }

        $segments = explode('/', $body);

        return match ($scheme) {
            self::VAULT => self::vault($segments, $key, $value, $fail),
            self::AWS => self::aws($body, $key, $value, $fail),
            self::AZURE => self::azure($segments, $key, $value, $fail),
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private static function vault(array $segments, ?string $key, string $raw, \Closure $fail): self
    {
        if ($key === null || count($segments) < 2 || self::badSegments($segments)
            || in_array(strtolower($segments[0]), self::VAULT_RESERVED_MOUNTS, true)) {
            throw $fail();
        }

        return new self(self::VAULT, implode('/', $segments), $segments, $key, $raw);
    }

    private static function aws(string $secretId, ?string $key, string $raw, \Closure $fail): self
    {
        // A dot segment has no meaning to AWS but would let a name slip past a
        // team's prefix when compared as text.
        if (preg_match('#^[A-Za-z0-9/_+=.@:-]{1,2048}$#', $secretId) !== 1
            || array_intersect(explode('/', $secretId), ['', '.', '..']) !== []) {
            throw $fail();
        }

        return new self(self::AWS, $secretId, [$secretId], $key, $raw);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function azure(array $segments, ?string $key, string $raw, \Closure $fail): self
    {
        $count = count($segments);

        if ($key !== null || $count < 2 || $count > 3) {
            throw $fail();
        }

        [$vault, $name] = $segments;
        $version = $segments[2] ?? null;

        if (preg_match('/^[A-Za-z][A-Za-z0-9-]{1,22}[A-Za-z0-9]$/', $vault) !== 1
            || preg_match('/^[A-Za-z0-9-]{1,127}$/', $name) !== 1
            || ($version !== null && preg_match('/^[A-Za-z0-9]{1,64}$/', $version) !== 1)) {
            throw $fail();
        }

        return new self(self::AZURE, implode('/', $segments), $segments, null, $raw);
    }

    /** @param  list<string>  $segments */
    private static function badSegments(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match(self::SEGMENT, $segment) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * `scheme://location` in the form prefixes are matched against. Azure Key
     * Vault names are case-insensitive, so that scheme is lower-cased; the
     * scheme itself always is. The `#key` and any other part are not included.
     */
    public function address(): string
    {
        $location = $this->scheme === self::AZURE ? strtolower($this->location) : $this->location;

        return $this->scheme.'://'.$location;
    }

    /**
     * Is this reference at or below one of the (normalized) prefixes? The
     * comparison is on whole path segments: `vault://secret/teams/pay` covers
     * `vault://secret/teams/pay/x` but not `vault://secret/teams/payments/x`.
     * The reference was parsed first, so it has no `..`, empty segment, `%`
     * escape or case-variant scheme left to slip past a prefix.
     *
     * @param  list<string>  $prefixes  as returned by normalizePrefix()
     */
    public function fallsUnder(array $prefixes): bool
    {
        $address = $this->address();

        foreach ($prefixes as $prefix) {
            if ($address === $prefix || str_starts_with($address, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate and normalize a prefix an administrator typed:
     * `<scheme>://<segment>[/<segment>...]`, no key, no wildcard, no dot or
     * empty segments, no trailing slash, scheme (and for Azure the whole
     * prefix) in lower case.
     *
     * @throws SecretResolutionException with reason invalid_reference
     */
    public static function normalizePrefix(string $prefix): string
    {
        $value = rtrim(trim($prefix), '/');
        $scheme = self::schemeOf($value);
        $fail = fn () => new SecretResolutionException(SecretResolutionException::INVALID_REFERENCE, $scheme);

        if ($scheme === null || strlen($value) > 512 || preg_match('/[\x00-\x20\x7f?#*%]/', $value) === 1) {
            throw $fail();
        }

        $body = substr($value, strlen($scheme) + 3);
        $segments = explode('/', $body);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._~@=+:-]+$/', $segment) !== 1) {
                throw $fail();
            }
        }

        if ($scheme === self::VAULT && in_array(strtolower($segments[0]), self::VAULT_RESERVED_MOUNTS, true)) {
            throw $fail();
        }

        return $scheme.'://'.($scheme === self::AZURE ? strtolower($body) : $body);
    }
}
