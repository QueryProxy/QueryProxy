<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The few Slack Web API methods the QueryProxy app needs (ADR-027).
 *
 * The base URL comes from configuration, never from user input, and redirects
 * are not followed. The bot token travels in the Authorization header only;
 * exceptions carry the method name and Slack's error code, nothing else.
 */
class SlackClient
{
    /**
     * @param  array<string, mixed>  $message  text, blocks, ...
     * @return array{channel: string, ts: string}
     *
     * @throws SlackApiException
     */
    public function postMessage(string $token, string $channel, array $message, ?string $threadTs = null): array
    {
        $response = $this->json('chat.postMessage', $token, [
            ...$message,
            'channel' => $channel,
            'unfurl_links' => false,
            'unfurl_media' => false,
            ...($threadTs !== null ? ['thread_ts' => $threadTs] : []),
        ]);

        return ['channel' => (string) ($response['channel'] ?? $channel), 'ts' => (string) ($response['ts'] ?? '')];
    }

    /**
     * @param  array<string, mixed>  $message  text and blocks that replace the old ones
     *
     * @throws SlackApiException
     */
    public function updateMessage(string $token, string $channel, string $ts, array $message): void
    {
        $this->json('chat.update', $token, [...$message, 'channel' => $channel, 'ts' => $ts]);
    }

    /**
     * @param  array<string, mixed>  $view
     *
     * @throws SlackApiException
     */
    public function openView(string $token, string $triggerId, array $view): void
    {
        $this->json('views.open', $token, ['trigger_id' => $triggerId, 'view' => $view]);
    }

    /**
     * The workspace member with this e-mail address, or null when there is none.
     *
     * @return array{id: string, is_bot: bool, deleted: bool}|null
     *
     * @throws SlackApiException
     */
    public function lookupUserByEmail(string $token, string $email): ?array
    {
        try {
            $response = $this->call('users.lookupByEmail', $this->client($token)->asForm(), ['email' => $email]);
        } catch (SlackApiException $e) {
            if ($e->slackError === 'users_not_found') {
                return null;
            }

            throw $e;
        }

        $user = $response['user'] ?? null;

        if (! is_array($user) || ! is_string($user['id'] ?? null)) {
            return null;
        }

        return [
            'id' => $user['id'],
            'is_bot' => (bool) ($user['is_bot'] ?? false),
            'deleted' => (bool) ($user['deleted'] ?? false),
        ];
    }

    /**
     * Trade the one-time `code` of the OAuth v2 redirect for the bot token.
     *
     * @return array<string, mixed> Slack's oauth.v2.access answer
     *
     * @throws SlackApiException
     */
    public function exchangeOAuthCode(string $code, string $redirectUri): array
    {
        $client = $this->client(null)
            ->withBasicAuth((string) config('services.slack.app.client_id'), (string) config('services.slack.app.client_secret'))
            ->asForm();

        return $this->call('oauth.v2.access', $client, ['code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    private function json(string $method, string $token, array $body): array
    {
        return $this->call($method, $this->client($token)->asJson(), $body);
    }

    private function client(?string $token): PendingRequest
    {
        $client = Http::timeout(5)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false]);

        return $token === null ? $client : $client->withToken($token);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, PendingRequest $client, array $body): array
    {
        try {
            $response = $client->post(config('queryproxy.slack_api_url').'/'.$method, $body);
        } catch (ConnectionException) {
            throw new SlackApiException($method, 'connection_failed');
        }

        if ($response->status() === 429) {
            throw new SlackApiException($method, 'ratelimited');
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw new SlackApiException($method, 'http_'.$response->status());
        }

        if (($data['ok'] ?? false) !== true) {
            throw new SlackApiException($method, (string) ($data['error'] ?? 'unknown_error'));
        }

        return $data;
    }
}
