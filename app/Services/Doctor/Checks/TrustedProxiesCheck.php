<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;

/**
 * Trusting every proxy lets any client forge X-Forwarded-For: login and 2FA
 * throttles (keyed per IP) are bypassed and the audit trail records a false
 * client IP (ADR-016).
 */
class TrustedProxiesCheck extends BaseCheck
{
    /** Entries that trust everyone. */
    private const OPEN = ['*', '**', '0.0.0.0/0', '::/0'];

    public function name(): string
    {
        return 'trusted_proxies';
    }

    public function run(): CheckResult
    {
        $proxies = array_map('trim', (array) config('queryproxy.trusted_proxies', []));

        if (array_intersect($proxies, self::OPEN) !== []) {
            return $this->fail('TRUSTED_PROXIES trusts every client ("'.implode('", "', array_intersect($proxies, self::OPEN)).'"); X-Forwarded-For can be forged. List the proxy IPs or CIDRs instead.');
        }

        return $proxies === []
            ? $this->ok('No proxy is trusted (right when the app is reached directly).')
            : $this->ok(count($proxies).' trusted prox'.(count($proxies) === 1 ? 'y' : 'ies').'.');
    }
}
