<?php

namespace App\Services\Audit\Sinks;

/**
 * RFC 5424 syslog over UDP, TCP or TLS. The message body (MSG) is the event
 * JSON; MSGID is the event name, so receivers can route on it.
 *
 * TCP and TLS frame each message with octet counting (RFC 6587 section 3.4.1,
 * mandatory for TLS in RFC 5425); `newline` framing exists for receivers that
 * only split on line ends. TLS always verifies the server certificate and its
 * name; a private CA is trusted through `tls_ca_file`, there is no switch to
 * turn verification off.
 *
 * UDP carries one event per datagram and has no delivery guarantee; large
 * events (long error messages) are better sent over TCP or TLS.
 */
class SyslogSink implements AuditSink
{
    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    public const FRAMINGS = ['octet-counting', 'newline'];

    private const SEVERITY_INFORMATIONAL = 6;

    /**
     * @param  array{host?: ?string, port?: int|string|null, transport?: string, framing?: string, facility?: int|string, app_name?: string, hostname?: ?string, tls_ca_file?: ?string, timeout?: int|string}  $config
     */
    public function __construct(
        private readonly AuditEventFormatter $formatter,
        private readonly array $config,
    ) {}

    /**
     * Whether the configuration can be used. Returns the reason when it
     * cannot, so the registry can log it once.
     */
    public static function configurationProblem(array $config): ?string
    {
        if (trim((string) ($config['host'] ?? '')) === '') {
            return 'QUERYPROXY_SIEM_SYSLOG_HOST is not set.';
        }

        if (! in_array($config['transport'] ?? 'udp', self::TRANSPORTS, true)) {
            return 'QUERYPROXY_SIEM_SYSLOG_TRANSPORT must be one of: '.implode(', ', self::TRANSPORTS).'.';
        }

        if (! in_array($config['framing'] ?? 'octet-counting', self::FRAMINGS, true)) {
            return 'QUERYPROXY_SIEM_SYSLOG_FRAMING must be one of: '.implode(', ', self::FRAMINGS).'.';
        }

        $port = (int) ($config['port'] ?? 0);

        if ($port < 1 || $port > 65535) {
            return 'QUERYPROXY_SIEM_SYSLOG_PORT must be between 1 and 65535.';
        }

        $facility = $config['facility'] ?? 13;

        if (! is_numeric($facility) || (int) $facility < 0 || (int) $facility > 23) {
            return 'QUERYPROXY_SIEM_SYSLOG_FACILITY must be between 0 and 23.';
        }

        return null;
    }

    public function name(): string
    {
        return 'syslog';
    }

    public function deliver(array $event): void
    {
        $message = $this->message($event);
        $transport = $this->transport();

        if ($transport !== 'udp') {
            $message = $this->frame($message);
        }

        $socket = @stream_socket_client(
            $this->address(),
            $errorCode,
            $errorMessage,
            $this->timeout(),
            STREAM_CLIENT_CONNECT,
            stream_context_create($this->contextOptions()),
        );

        if ($socket === false) {
            throw new SinkDeliveryException("syslog sink: cannot connect ({$errorCode}) ".$this->printable((string) $errorMessage));
        }

        try {
            stream_set_timeout($socket, $this->timeout());

            // UDP is one datagram per write; a stream may take the bytes in pieces.
            $offset = 0;
            $length = strlen($message);

            while ($offset < $length) {
                $written = @fwrite($socket, substr($message, $offset));

                if ($written === false || $written === 0) {
                    throw new SinkDeliveryException('syslog sink: the message was not written completely.');
                }

                $offset += $written;
            }
        } finally {
            fclose($socket);
        }
    }

    public function tries(): int
    {
        return 4;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** `udp://host:514`, `tcp://host:514` or `tls://host:6514`. */
    public function address(): string
    {
        $host = trim((string) $this->config['host']);

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = "[{$host}]";
        }

        return $this->transport().'://'.$host.':'.(int) $this->config['port'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function contextOptions(): array
    {
        if ($this->transport() !== 'tls') {
            return [];
        }

        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim((string) $this->config['host'], '[]'),
            'allow_self_signed' => false,
        ];

        $caFile = trim((string) ($this->config['tls_ca_file'] ?? ''));

        if ($caFile !== '') {
            $options['cafile'] = $caFile;
        }

        return ['ssl' => $options];
    }

    /**
     * The RFC 5424 message: `<PRI>1 TIMESTAMP HOSTNAME APP-NAME PROCID MSGID SD MSG`.
     *
     * @param  array<string, mixed>  $event
     */
    public function message(array $event): string
    {
        $priority = ((int) ($this->config['facility'] ?? 13)) * 8 + self::SEVERITY_INFORMATIONAL;

        return sprintf(
            '<%d>1 %s %s %s - %s - %s',
            $priority,
            $event['timestamp'],
            $this->header(($this->config['hostname'] ?? '') ?: (string) gethostname(), 255),
            $this->header((string) ($this->config['app_name'] ?? 'queryproxy') ?: 'queryproxy', 48),
            $this->header((string) $event['action'], 32),
            $this->formatter->json($event),
        );
    }

    private function frame(string $message): string
    {
        if (($this->config['framing'] ?? 'octet-counting') === 'newline') {
            return $message."\n";
        }

        return strlen($message).' '.$message;
    }

    private function transport(): string
    {
        return (string) ($this->config['transport'] ?? 'udp');
    }

    private function timeout(): int
    {
        return max(1, (int) ($this->config['timeout'] ?? 5));
    }

    /**
     * A header field is printable ASCII without spaces (RFC 5424 section 6),
     * at most $max long, or the NILVALUE.
     */
    private function header(string $value, int $max): string
    {
        $value = substr((string) preg_replace('/[^\x21-\x7e]/', '', $value), 0, $max);

        return $value === '' ? '-' : $value;
    }

    private function printable(string $value): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 200);
    }
}
