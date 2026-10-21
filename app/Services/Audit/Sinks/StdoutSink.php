<?php

namespace App\Services\Audit\Sinks;

/**
 * One JSON object per line on the worker's standard output. Container log
 * collectors (Fluent Bit, Vector, Promtail) pick it up from there.
 *
 * Events are queued, so the line appears on the stdout of the queue worker
 * process, not of the web process.
 */
class StdoutSink implements AuditSink
{
    public function __construct(
        private readonly AuditEventFormatter $formatter,
        private readonly string $stream = 'php://stdout',
    ) {}

    public function name(): string
    {
        return 'stdout';
    }

    public function deliver(array $event): void
    {
        $line = $this->formatter->json($event)."\n";

        $handle = @fopen($this->stream, 'ab');

        if ($handle === false) {
            throw new SinkDeliveryException('stdout sink: cannot open the output stream.');
        }

        try {
            $written = @fwrite($handle, $line);
        } finally {
            fclose($handle);
        }

        if ($written !== strlen($line)) {
            throw new SinkDeliveryException('stdout sink: the event line was not written completely.');
        }
    }

    public function tries(): int
    {
        return 3;
    }

    public function backoff(): array
    {
        return [5, 30];
    }
}
