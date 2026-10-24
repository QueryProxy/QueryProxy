<?php

namespace App\Services\Doctor\Checks;

use App\Services\Audit\Sinks\SinkRegistry;
use App\Services\Audit\Sinks\SyslogSink;
use App\Services\Audit\Sinks\WebhookSink;
use App\Services\Doctor\CheckResult;

/**
 * A SIEM sink that is named but misconfigured is silently left out at run time
 * (SinkRegistry logs it once); the audit export stops without anyone noticing.
 * Uses the sinks' own validation, so the two cannot disagree.
 */
class SiemCheck extends BaseCheck
{
    public function name(): string
    {
        return 'siem_sinks';
    }

    public function run(): CheckResult
    {
        $requested = array_values(array_unique(array_filter(array_map(
            fn ($name) => strtolower(trim((string) $name)),
            (array) config('queryproxy.siem.sinks', []),
        ))));

        if ($requested === []) {
            return $this->skip('No SIEM sink is turned on (QUERYPROXY_SIEM_SINKS).');
        }

        $problems = [];
        $warnings = [];

        foreach ($requested as $name) {
            $config = (array) config("queryproxy.siem.{$name}", []);

            if (! in_array($name, SinkRegistry::NAMES, true)) {
                $problems[] = "unknown sink \"{$name}\"";

                continue;
            }

            $problem = match ($name) {
                'syslog' => SyslogSink::configurationProblem($config),
                'webhook' => WebhookSink::configurationProblem($config),
                default => null,
            };

            if ($problem !== null) {
                $problems[] = "{$name}: {$problem}";

                continue;
            }

            if ($name === 'webhook' && strtolower((string) parse_url((string) $config['url'], PHP_URL_SCHEME)) === 'http') {
                $warnings[] = 'the webhook URL is plain http; events travel unencrypted';
            }

            if ($name === 'syslog' && ($config['transport'] ?? 'udp') === 'udp') {
                $warnings[] = 'syslog over UDP is unencrypted and unacknowledged';
            }

            $ca = (string) ($config['tls_ca_file'] ?? '');

            if ($name === 'syslog' && ($config['transport'] ?? '') === 'tls' && $ca !== '' && ! is_readable($ca)) {
                $problems[] = "syslog: QUERYPROXY_SIEM_SYSLOG_TLS_CA_FILE \"{$ca}\" is not a readable file.";
            }
        }

        if ($problems !== []) {
            return $this->fail('Sink(s) would not be used, audit events are not exported: '.implode('; ', $problems));
        }

        return $warnings !== []
            ? $this->warn(implode('; ', $warnings).'.')
            : $this->ok('Sinks configured: '.implode(', ', $requested).'.');
    }
}
