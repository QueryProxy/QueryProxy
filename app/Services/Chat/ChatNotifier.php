<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Enums\QueryRequestStatus;
use App\Models\ChatApprovalToken;
use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts request lifecycle messages to the team's configured chat channels.
 * Failures are logged, never thrown — chat being down must not break the flow.
 */
class ChatNotifier
{
    public function requestSubmitted(QueryRequest $request): void
    {
        foreach ($this->integrations($request) as $integration) {
            // One token per integration, so a decision taken in Slack does not
            // silently invalidate the Teams card and vice versa.
            $token = ChatApprovalToken::issueFor($request);

            $payload = $integration->provider === ChatProvider::Slack
                ? $this->slackSubmittedMessage($request, $token)
                : $this->teamsSubmittedCard($request, $token);

            $this->post($integration, $payload);
        }
    }

    public function requestDecided(QueryRequest $request): void
    {
        $approved = $request->status !== QueryRequestStatus::Rejected;

        $text = sprintf(
            '%s Request #%d (%s) was %s by %s.',
            $approved ? '✅' : '⛔',
            $request->id,
            $request->connection->name,
            $approved ? 'approved' : 'rejected',
            $request->reviewer?->name ?? 'a reviewer',
        );

        foreach ($this->integrations($request) as $integration) {
            $payload = $integration->provider === ChatProvider::Slack
                ? ['text' => $text]
                : ['type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => $text, 'text' => $text];

            $this->post($integration, $payload);
        }
    }

    /**
     * @return iterable<ChatIntegration>
     */
    private function integrations(QueryRequest $request): iterable
    {
        return ChatIntegration::query()
            ->where('team_id', $request->team_id)
            ->where('enabled', true)
            ->get();
    }

    private function post(ChatIntegration $integration, array $payload): void
    {
        try {
            // No redirects: an allowed host must not be able to bounce the
            // request to an internal target (SSRF via 30x).
            Http::timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($integration->webhook_url, $payload)
                ->throw();
        } catch (Throwable $e) {
            Log::warning('QueryProxy: chat notification failed', [
                'team_id' => $integration->team_id,
                'provider' => $integration->provider->value,
                'error' => $this->redactWebhookUrl($e->getMessage(), $integration),
            ]);
        }
    }

    /**
     * The webhook URL *is* the bearer credential — for a Slack incoming hook
     * the secret is the path itself — which is why it is stored encrypted and
     * never echoed back to the UI. Transport failures blow a hole in that:
     * Guzzle wraps the cURL message including the full effective URL, and its
     * own redaction only masks userinfo, never the path. So scrub the URL out
     * of anything derived from the exception before it reaches the log, the
     * same way DynamicConnectionFactory::redactError() scrubs DSN secrets.
     */
    private function redactWebhookUrl(string $message, ChatIntegration $integration): string
    {
        $url = (string) $integration->webhook_url;

        return $url === '' ? $message : str_replace($url, '[redacted-webhook]', $message);
    }

    /**
     * Slack Block Kit message with interactive Approve / Reject buttons.
     * Button clicks arrive at /webhooks/slack/interactions (HMAC-verified).
     *
     * The button values carry the single-use action token alongside the
     * request id; the callback endpoint refuses any value without one.
     *
     * @return array<string, mixed>
     */
    private function slackSubmittedMessage(QueryRequest $request, string $token): array
    {
        $sqlPreview = $this->sqlPreview($request);

        return [
            'text' => sprintf('New query request #%d from %s', $request->id, $request->requester->name),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            "*New %s request #%d*\n*Requester:* %s\n*Connection:* %s%s",
                            strtoupper($request->type->value),
                            $request->id,
                            $request->requester->name,
                            $request->connection->name,
                            $request->title ? "\n*Title:* {$request->title}" : '',
                        ),
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "```{$sqlPreview}```"],
                ],
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'action_id' => 'queryproxy_approve',
                            'value' => "approve:{$request->id}:{$token}",
                            'confirm' => [
                                'title' => ['type' => 'plain_text', 'text' => 'Approve request?'],
                                'text' => ['type' => 'plain_text', 'text' => "Request #{$request->id} will be executed."],
                                'confirm' => ['type' => 'plain_text', 'text' => 'Approve'],
                                'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
                            ],
                        ],
                        [
                            'type' => 'button',
                            'style' => 'danger',
                            'text' => ['type' => 'plain_text', 'text' => 'Reject'],
                            'action_id' => 'queryproxy_reject',
                            'value' => "reject:{$request->id}:{$token}",
                        ],
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Open in QueryProxy'],
                            'action_id' => 'queryproxy_open',
                            'url' => route('requests.show', $request),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** SQL literals can carry sensitive values; sending them to chat is opt-out. */
    private function sqlPreview(QueryRequest $request): string
    {
        if (! config('queryproxy.chat_include_sql', true)) {
            return '(SQL preview disabled — review in QueryProxy)';
        }

        return (string) str($request->sql_prepared)->limit(400);
    }

    /**
     * Teams MessageCard: informational with a deep link (interactive approval
     * for Teams runs through the generic HMAC action endpoint, e.g. from a
     * Power Automate flow).
     *
     * The single-use action token travels in a `queryproxy` envelope rather
     * than a visible fact: MessageCard renderers ignore unknown top-level
     * keys, so the automation reading the payload can pick it up without the
     * token being splashed across the channel.
     *
     * @return array<string, mixed>
     */
    private function teamsSubmittedCard(QueryRequest $request, string $token): array
    {
        return [
            'queryproxy' => [
                'request_id' => $request->id,
                'action_token' => $token,
            ],
            'type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => sprintf('New query request #%d', $request->id),
            'themeColor' => '6366F1',
            'title' => sprintf('New %s request #%d', strtoupper($request->type->value), $request->id),
            'sections' => [
                [
                    'facts' => [
                        ['name' => 'Requester', 'value' => $request->requester->name],
                        ['name' => 'Connection', 'value' => $request->connection->name],
                        ['name' => 'Statements', 'value' => (string) $request->statement_count],
                    ],
                    'text' => '```'.$this->sqlPreview($request).'```',
                ],
            ],
            'potentialAction' => [
                [
                    '@type' => 'OpenUri',
                    'name' => 'Review in QueryProxy',
                    'targets' => [['os' => 'default', 'uri' => route('requests.show', $request)]],
                ],
            ],
        ];
    }
}
