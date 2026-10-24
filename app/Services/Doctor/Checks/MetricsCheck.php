<?php

namespace App\Services\Doctor\Checks;

use App\Services\Doctor\CheckResult;
use App\Support\SecretFile;
use RuntimeException;

/**
 * /metrics (ADR-022) is off unless QUERYPROXY_METRICS_TOKEN or an IP allowlist
 * is configured. This looks at the token only: a token short enough to guess
 * defeats the Bearer check.
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

        if ($token === null) {
            return $this->skip('No QUERYPROXY_METRICS_TOKEN; /metrics is off unless an IP allowlist is configured.');
        }

        if (strlen($token) < self::MIN_TOKEN_LENGTH) {
            return $this->warn('QUERYPROXY_METRICS_TOKEN is shorter than '.self::MIN_TOKEN_LENGTH.' characters; use a random value (openssl rand -hex 24).');
        }

        return $this->ok('/metrics is protected by a bearer token.');
    }
}
