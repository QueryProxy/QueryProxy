<?php

namespace App\Services\Audit\Sinks;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs each event as JSON to one URL, signed with HMAC-SHA256.
 *
 * Headers:
 *   X-QueryProxy-Timestamp  Unix seconds at the moment of this attempt.
 *   X-QueryProxy-Signature  `sha256=` + hex HMAC_SHA256("{timestamp}.{body}", secret).
 *   X-QueryProxy-Event      The event name (`request.approved`).
 *   X-QueryProxy-Event-Id   The audit record id; a retried delivery repeats it.
 *
 * A receiver recomputes the HMAC over the raw body, compares in constant time
 * and rejects timestamps outside its tolerance window. The URL may itself be
 * a credential (a token in the path or query), so no error message built here
 * contains it.
 */
class WebhookSink implements AuditSink
{
    public const MIN_SECRET_LENGTH = 16;

    /**
     * @param  array{url?: ?string, secret?: ?string, timeout?: int|string, tries?: int|string}  $config
     */
    public function __construct(
        private readonly AuditEventFormatter $formatter,
        private readonly array $config,
    ) {}

    /** The reason the configuration cannot be used, or null. */
    public static function configurationProblem(array $config): ?string
    {
        $url = trim((string) ($config['url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($url === '' || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'QUERYPROXY_SIEM_WEBHOOK_URL must be an http:// or https:// URL.';
        }

        if (strlen((string) ($config['secret'] ?? '')) < self::MIN_SECRET_LENGTH) {
            return 'QUERYPROXY_SIEM_WEBHOOK_SECRET must be at least '.self::MIN_SECRET_LENGTH.' characters; an unsigned webhook is not sent.';
        }

        return null;
    }

    public function name(): string
    {
        return 'webhook';
    }

    public function deliver(array $event): void
    {
        $url = trim((string) $this->config['url']);
        $body = $this->formatter->json($event);
        $timestamp = (string) time();

        try {
            // No redirects: a 30x must not move the signed body somewhere else.
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'User-Agent' => 'QueryProxy-Audit/'.AuditEventFormatter::SCHEMA_VERSION,
                    'X-QueryProxy-Timestamp' => $timestamp,
                    'X-QueryProxy-Signature' => $this->signature($timestamp, $body),
                    'X-QueryProxy-Event' => (string) $event['action'],
                    'X-QueryProxy-Event-Id' => (string) $event['id'],
                ])
                ->timeout(max(1, (int) ($this->config['timeout'] ?? 5)))
                ->withOptions(['allow_redirects' => false])
                ->post($url);
        } catch (Throwable $e) {
            throw new SinkDeliveryException('webhook sink: request failed: '.$this->scrub($e->getMessage(), $url));
        }

        if (! $response->successful()) {
            throw new SinkDeliveryException('webhook sink: the receiver answered HTTP '.$response->status().'.');
        }
    }

    public function signature(string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->config['secret']);
    }

    public function tries(): int
    {
        return max(1, (int) ($this->config['tries'] ?? 6));
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    /**
     * Guzzle puts the effective URL in its transport messages. Drop the URL,
     * and with it anything that follows from it (path, query, userinfo).
     */
    private function scrub(string $message, string $url): string
    {
        $message = str_replace($url, '[webhook-url]', $message);

        // The message may name the URL in a different spelling (userinfo
        // stripped, query re-encoded); remove any remaining URL outright.
        $message = (string) preg_replace('#https?://\S+#i', '[webhook-url]', $message);

        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $message), 0, 300);
    }
}
