<?php

namespace App\Services\Audit\Sinks;

use Illuminate\Support\Facades\Log;

/**
 * Builds the sinks the operator turned on (`queryproxy.siem.sinks`). A sink
 * that is named but misconfigured is left out and the reason is logged once
 * per process: a half-configured sink must not be able to fail audit writes
 * or flood the log.
 */
class SinkRegistry
{
    public const NAMES = ['stdout', 'syslog', 'webhook'];

    /**
     * Static on purpose: the registry is built afresh for every audit write
     * and every job, but a misconfiguration is reported once per process.
     *
     * @var array<string, true>
     */
    private static array $reported = [];

    public function __construct(private readonly AuditEventFormatter $formatter) {}

    /** @return list<string> names of the sinks that are on and usable */
    public function enabledNames(): array
    {
        return array_values(array_filter(
            $this->requested(),
            fn (string $name) => $this->make($name) !== null,
        ));
    }

    /** The sink, or null when it is off, unknown or misconfigured. */
    public function make(string $name): ?AuditSink
    {
        if (! in_array($name, $this->requested(), true)) {
            return null;
        }

        $config = (array) config("queryproxy.siem.{$name}", []);

        $problem = match ($name) {
            'syslog' => SyslogSink::configurationProblem($config),
            'webhook' => WebhookSink::configurationProblem($config),
            default => null,
        };

        if ($problem !== null) {
            $this->report($name, "SIEM sink \"{$name}\" is not used: {$problem}");

            return null;
        }

        return match ($name) {
            'stdout' => new StdoutSink($this->formatter, (string) ($config['stream'] ?? 'php://stdout') ?: 'php://stdout'),
            'syslog' => new SyslogSink($this->formatter, $config),
            'webhook' => new WebhookSink($this->formatter, $config),
            default => null,
        };
    }

    /** @return list<string> */
    private function requested(): array
    {
        $names = [];

        foreach ((array) config('queryproxy.siem.sinks', []) as $name) {
            $name = strtolower(trim((string) $name));

            if ($name === '') {
                continue;
            }

            if (! in_array($name, self::NAMES, true)) {
                $this->report("unknown:{$name}", "Unknown SIEM sink \"{$name}\" in QUERYPROXY_SIEM_SINKS (known: ".implode(', ', self::NAMES).').');

                continue;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /** Forget what was reported (tests, long-lived workers after a config reload). */
    public static function forgetReports(): void
    {
        self::$reported = [];
    }

    private function report(string $key, string $message): void
    {
        if (isset(self::$reported[$key])) {
            return;
        }

        self::$reported[$key] = true;

        Log::warning('QueryProxy: '.$message);
    }
}
