<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Support\SecretFile;
use RuntimeException;

/**
 * /metrics (ADR-022) is off unless QUERYPROXY_METRICS_TOKEN or an IP allowlist
 * is configured (config queryproxy.metrics.token / allowed_ips). A token short
 * enough to guess defeats the Bearer check, so its length is checked; an
 * allowlist entry that is not an IP address or CIDR range never matches a
 * client, so those are reported. Both problems are reported together; whether
 * an allowed address is the right one cannot be judged from here.
 */
class MetricsCheck extends BaseCheck
{
    /** Shortest token considered unguessable. */
    public const MIN_TOKEN_LENGTH = 24;

    public function name(): string
    {
        return 'metrics';
    }

    public function run(): CheckResult
    {
        try {
            $token = SecretFile::get('QUERYPROXY_METRICS_TOKEN');
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $allowedIps = array_filter((array) config('queryproxy.metrics.allowed_ips'));

        $problems = [];

        // A hostname or a typo matches no address, so those entries never let a client in.
        $invalid = array_values(array_filter($allowedIps, fn ($entry) => ! $this->isAddressOrRange((string) $entry)));

        if ($invalid !== []) {
            $problems[] = 'QUERYPROXY_METRICS_ALLOWED_IPS has entries that are not an IP address or CIDR range: '.implode(', ', $invalid).'. They never match a client.';
        }

        if ($token !== null && strlen($token) < self::MIN_TOKEN_LENGTH) {
            $problems[] = 'QUERYPROXY_METRICS_TOKEN is shorter than '.self::MIN_TOKEN_LENGTH.' characters; use a random value (openssl rand -hex 24).';
        }

        if ($problems !== []) {
            return $this->warn(implode(' ', $problems));
        }

        if ($token === null) {
            return $allowedIps === []
                ? $this->skip('/metrics is off: neither QUERYPROXY_METRICS_TOKEN nor QUERYPROXY_METRICS_ALLOWED_IPS is set.')
                : $this->ok('/metrics is limited to '.count($allowedIps).' allowed address range(s); no bearer token is set.');
        }

        return $this->ok('/metrics is protected by a bearer token.');
    }

    /** An IPv4 / IPv6 address, or one with a /prefix inside the family's range. */
    private function isAddressOrRange(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isV4 && ! $isV6) {
            return false;
        }

        return $prefix === null
            || (ctype_digit($prefix) && (int) $prefix <= ($isV4 ? 32 : 128));
    }
}
