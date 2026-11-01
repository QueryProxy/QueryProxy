<?php

namespace App\Services\Auth;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Relying party for one OpenID Connect provider (ADR-023): discovery, the
 * authorization code flow with PKCE, and validation of the ID token.
 *
 * Only asymmetric signatures are accepted, and the algorithm is taken from
 * the provider's published key, never from the token header, so a token
 * cannot pick its own verification method ("none", HMAC with the public key).
 * Neither the client secret, the code, nor any token is written to a log.
 */
class OidcClient
{
    /** Seconds of clock skew tolerated for exp / iat / nbf. */
    private const LEEWAY = 60;

    private const METADATA_TTL = 3600;

    private const HTTP_TIMEOUT = 10;

    /** Claims the userinfo response may never overwrite or add to the ID token. */
    private const PROTECTED_CLAIMS = ['iss', 'aud', 'exp', 'iat', 'nbf', 'nonce', 'sub', 'azp', 'auth_time'];

    public function isConfigured(): bool
    {
        return $this->setting('issuer') !== '' && $this->setting('client_id') !== '';
    }

    /** A fresh PKCE code verifier (RFC 7636: 43 to 128 unreserved characters). */
    public static function newCodeVerifier(): string
    {
        return self::base64Url(random_bytes(48));
    }

    /**
     * @param  bool  $forceLogin  ask the provider to authenticate the user again even if it still has
     *                            a session for them (`prompt=login`, `max_age=0`), for a fresh confirmation (ADR-024)
     */
    public function authorizationUrl(string $state, string $nonce, string $codeVerifier, string $redirectUri, bool $forceLogin = false): string
    {
        $endpoint = $this->metadata()['authorization_endpoint'];

        $scopes = preg_split('/\s+/', $this->setting('scopes'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        $query = http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => $forceLogin ? 'login' : null,
            'max_age' => $forceLogin ? 0 : null,
        ], fn ($value) => $value !== null), '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchanges the authorization code and returns the validated claims of the
     * ID token. When the token carries no claim named `$wantedClaim` (some
     * providers only release groups through the userinfo endpoint), the
     * userinfo response fills in what the token lacks.
     *
     * The raw ID token is handed back through `$rawIdToken` (after it passed
     * validation) for the caller to keep as the `id_token_hint` of a later
     * RP-initiated logout; it must not be logged or audited.
     *
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    public function fetchClaims(string $code, string $codeVerifier, string $nonce, string $redirectUri, ?string $wantedClaim = null, ?string &$rawIdToken = null): array
    {
        $metadata = $this->metadata();
        $tokens = $this->exchangeCode($metadata, $code, $codeVerifier, $redirectUri);

        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new OidcException('The token response has no id_token.');
        }

        $claims = $this->validatedClaims($idToken, $metadata, $nonce);
        $rawIdToken = $idToken;

        $accessToken = $tokens['access_token'] ?? null;
        // The userinfo endpoint is asked only for what the token lacks: the
        // group claim, or any statement about the e-mail being verified.
        $lacksGroups = $wantedClaim !== null && ! self::hasClaim($claims, $wantedClaim);
        $lacksVerification = ! isset($claims['email_verified']) && ! isset($claims['xms_edov']);

        if (($lacksGroups || $lacksVerification)
            && is_string($accessToken) && $accessToken !== ''
            && is_string($metadata['userinfo_endpoint'] ?? null)) {
            $claims = $this->mergeUserInfo($claims, $metadata['userinfo_endpoint'], $accessToken);
        }

        return $claims;
    }

    /**
     * The provider's `end_session_endpoint` (OIDC RP-Initiated Logout 1.0), or
     * null when it publishes none, the document cannot be read, or the value
     * is not an http(s) URL. Never throws: a logout must not fail on this.
     */
    public function endSessionEndpoint(): ?string
    {
        try {
            $endpoint = $this->metadata()['end_session_endpoint'] ?? null;
        } catch (OidcException) {
            return null;
        }

        return is_string($endpoint) && preg_match('#^https?://#i', $endpoint) ? $endpoint : null;
    }

    /**
     * The URL that ends the session at the provider, or null when it offers no
     * `end_session_endpoint`. The caller passes a fixed, registered return
     * address; it is never derived from a request.
     */
    public function logoutUrl(?string $idTokenHint, string $postLogoutRedirectUri): ?string
    {
        $endpoint = $this->endSessionEndpoint();

        if ($endpoint === null) {
            return null;
        }

        $query = http_build_query(array_filter([
            'id_token_hint' => $idTokenHint,
            'client_id' => $this->setting('client_id'),
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ], fn ($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /** Reads a claim by its exact name first, then as a dotted path (realm_access.roles). */
    public static function claim(array $claims, string $name): mixed
    {
        if (array_key_exists($name, $claims)) {
            return $claims[$name];
        }

        $value = $claims;
        foreach (explode('.', $name) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function hasClaim(array $claims, string $name): bool
    {
        return self::claim($claims, $name) !== null;
    }

    /**
     * @return array<string, mixed> the discovery document, checked for the fields the flow needs
     *
     * @throws OidcException
     */
    public function metadata(): array
    {
        $issuer = $this->setting('issuer');
        if (! $this->isConfigured() || ! preg_match('#^https?://#i', $issuer)) {
            throw new OidcException('OIDC is not configured, or the issuer is not an http(s) URL.');
        }

        $cacheKey = 'queryproxy.oidc.metadata.'.sha1($issuer);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $document = $this->getJson(rtrim($issuer, '/').'/.well-known/openid-configuration');

        // The document must describe the issuer it was fetched for (RFC 8414
        // section 3.3), or a provider could vouch for another one.
        if (! is_string($document['issuer'] ?? null) || rtrim($document['issuer'], '/') !== rtrim($issuer, '/')) {
            throw new OidcException('The discovery document issuer does not match the configured issuer.');
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($document[$field] ?? null) || ! preg_match('#^https?://#i', $document[$field])) {
                throw new OidcException("The discovery document has no usable {$field}.");
            }
        }

        Cache::put($cacheKey, $document, self::METADATA_TTL);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function exchangeCode(array $metadata, string $code, string $codeVerifier, string $redirectUri): array
    {
        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $methods = is_array($methods) ? $methods : ['client_secret_basic'];
        $secret = $this->setting('client_secret');
        $clientId = $this->setting('client_id');

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'client_id' => $clientId,
        ];

        $request = $this->http()->asForm();

        if ($secret !== '') {
            if (! in_array('client_secret_basic', $methods, true) && in_array('client_secret_post', $methods, true)) {
                $form['client_secret'] = $secret;
            } else {
                // RFC 6749 section 2.3.1: the credentials are form-urlencoded before the Basic encoding.
                $request = $request->withBasicAuth(urlencode($clientId), urlencode($secret));
            }
        }

        try {
            $response = $request->post($metadata['token_endpoint'], $form);
        } catch (Throwable $e) {
            throw new OidcException('The token request failed: '.get_class($e), previous: $e);
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $error = is_string($error) ? substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $error), 0, 64) : 'unknown';

            throw new OidcException('The token endpoint refused the code (HTTP '.$response->status().', error '.$error.').');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new OidcException('The token endpoint did not return JSON.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function validatedClaims(string $idToken, array $metadata, string $nonce): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            try {
                $payload = JWT::decode($idToken, $this->keys($metadata['jwks_uri']));
            } catch (UnexpectedValueException $e) {
                // An expired or not-yet-valid token is final. Anything else
                // (unknown kid, bad signature) is most likely a key rotation
                // since the set was cached: read the set once more and try again.
                if ($e instanceof ExpiredException || $e instanceof BeforeValidException) {
                    throw $e;
                }

                $payload = JWT::decode($idToken, $this->keys($metadata['jwks_uri'], refresh: true));
            }
        } catch (OidcException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OidcException('The ID token was rejected: '.$e->getMessage(), previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($payload), true) ?: [];

        $clientId = $this->setting('client_id');

        if (($claims['iss'] ?? null) !== $metadata['issuer']) {
            throw new OidcException('The ID token issuer does not match the provider.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($clientId, $audiences, true)) {
            throw new OidcException('The ID token audience does not include this client.');
        }
        if ((count($audiences) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $clientId) {
            throw new OidcException('The ID token authorized party is not this client.');
        }

        if (! is_numeric($claims['exp'] ?? null) || ! is_numeric($claims['iat'] ?? null)) {
            throw new OidcException('The ID token lacks exp or iat.');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('The ID token nonce does not match the login attempt.');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new OidcException('The ID token has no usable subject.');
        }

        return $claims;
    }

    /**
     * @return array<string, Key>
     *
     * @throws OidcException
     */
    private function keys(string $jwksUri, bool $refresh = false): array
    {
        $cacheKey = 'queryproxy.oidc.jwks.'.sha1($jwksUri);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::get($cacheKey);

        if (! is_array($jwks)) {
            $jwks = $this->getJson($jwksUri);
            Cache::put($cacheKey, $jwks, self::METADATA_TTL);
        }

        $usable = [];

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (! is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
                continue;
            }

            // Symmetric keys have no place in a public key set.
            $algorithm = match ($jwk['kty'] ?? null) {
                'RSA' => 'RS256',
                'EC' => match ($jwk['crv'] ?? null) {
                    'P-256' => 'ES256',
                    'P-384' => 'ES384',
                    default => null,
                },
                'OKP' => 'EdDSA',
                default => null,
            };

            if ($algorithm === null) {
                continue;
            }

            // A key that states its algorithm keeps it; one that does not gets
            // the algorithm its type implies.
            $jwk['alg'] ??= $algorithm;

            $usable[] = $jwk;
        }

        if ($usable === []) {
            throw new OidcException('The provider publishes no usable signing key.');
        }

        try {
            return JWK::parseKeySet(['keys' => $usable]);
        } catch (Throwable $e) {
            throw new OidcException('The provider key set could not be read: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function mergeUserInfo(array $claims, string $endpoint, string $accessToken): array
    {
        try {
            $response = $this->http()->withToken($accessToken)->acceptJson()->get($endpoint);
        } catch (Throwable $e) {
            Log::warning('OIDC userinfo request failed.', ['exception' => get_class($e)]);

            return $claims;
        }

        $info = $response->successful() ? $response->json() : null;

        // The userinfo subject must be the ID token subject (OIDC Core 5.3.2).
        if (! is_array($info) || ($info['sub'] ?? null) !== $claims['sub']) {
            Log::warning('OIDC userinfo response ignored: no usable body or subject mismatch.', ['status' => $response->status()]);

            return $claims;
        }

        return $claims + array_diff_key($info, array_flip(self::PROTECTED_CLAIMS));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OidcException
     */
    private function getJson(string $url): array
    {
        try {
            $response = $this->http()->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new OidcException('Could not reach '.parse_url($url, PHP_URL_HOST).': '.get_class($e), previous: $e);
        }

        $body = $response->successful() ? $response->json() : null;

        if (! is_array($body)) {
            throw new OidcException('GET '.parse_url($url, PHP_URL_PATH).' returned HTTP '.$response->status().' without a JSON object.');
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(self::HTTP_TIMEOUT)->withoutRedirecting();
    }

    private function setting(string $key): string
    {
        return (string) config('queryproxy.oidc.'.$key, '');
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
