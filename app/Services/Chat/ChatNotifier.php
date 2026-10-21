<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Enums\ChatSqlMode;
use App\Enums\QueryRequestStatus;
use App\Models\ChatApprovalToken;
use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use App\Services\Sql\SqlRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts request lifecycle messages to the team's configured chat channels.
 * Failures are logged, never thrown — chat being down must not break the flow.
 */
class ChatNotifier
{
    /**
     * Teams webhook hosts that belong to Workflows (Power Automate), which
     * expects an Adaptive Card message. Every other Teams host is a legacy
     * Office 365 connector URL and keeps receiving the MessageCard.
     */
    public const WORKFLOWS_HOSTS = ['*.powerplatform.com', '*.logic.azure.com'];

    public function requestSubmitted(QueryRequest $request): void
    {
        $this->postRequestCard($request);
    }

    /**
     * The "still waiting" nudge. It carries fresh action tokens, so the
     * buttons work even when the original message has aged out.
     */
    public function requestReminder(QueryRequest $request): void
    {
        $this->postRequestCard($request, sprintf(
            '⏰ Reminder: request #%d has been waiting for review since %s.',
            $request->id,
            $request->created_at->copy()->utc()->toDayDateTimeString().' UTC',
        ));
    }

    /**
     * The buttons of the earlier message have expired while the request is
     * still pending: post the card again with new tokens.
     */
    public function requestCardRenewed(QueryRequest $request): void
    {
        $this->postRequestCard($request, sprintf(
            '🔄 The buttons on the earlier message for request #%d have expired. Use the buttons on this message.',
            $request->id,
        ));
    }

    private function postRequestCard(QueryRequest $request, ?string $lead = null): void
    {
        foreach ($this->integrations($request) as $integration) {
            // One token per integration, so a decision taken in Slack does not
            // silently invalidate the Teams card and vice versa.
            $token = ChatApprovalToken::issueFor($request);

            $payload = match (true) {
                $integration->provider === ChatProvider::Slack => $this->slackSubmittedMessage($request, $token, $lead),
                $this->usesAdaptiveCard($integration) => $this->teamsAdaptiveCard($request, $token, $lead),
                default => $this->teamsSubmittedCard($request, $token, $lead),
            };

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
            $payload = match (true) {
                $integration->provider === ChatProvider::Slack => ['text' => $text],
                $this->usesAdaptiveCard($integration) => $this->adaptiveMessage([
                    ['type' => 'TextBlock', 'text' => $text, 'wrap' => true],
                ], $text),
                default => ['type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => $text, 'text' => $text],
            };

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

    /** Is this Teams integration a Workflows URL (Adaptive Card) rather than a legacy connector? */
    private function usesAdaptiveCard(ChatIntegration $integration): bool
    {
        if ($integration->provider !== ChatProvider::Teams) {
            return false;
        }

        $host = strtolower((string) parse_url((string) $integration->webhook_url, PHP_URL_HOST));

        return collect(self::WORKFLOWS_HOSTS)->contains(fn (string $pattern) => Str::is($pattern, $host));
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
    private function slackSubmittedMessage(QueryRequest $request, string $token, ?string $lead = null): array
    {
        $sqlPreview = $this->sqlPreview($request);

        return [
            'text' => ($lead ? $lead."\n" : '').sprintf(
                'New query request #%d from %s%s',
                $request->id,
                $request->requester->name,
                $request->is_ddl ? ' (DDL)' : '',
            ),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ($lead ? "_{$lead}_\n" : '').sprintf(
                            "*New %s request #%d*%s\n*Requester:* %s\n*Connection:* %s%s",
                            strtoupper($request->type->value),
                            $request->id,
                            $request->is_ddl ? ' · *DDL*' : '',
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

    /**
     * SQL literals can carry sensitive values (ADR-017): by default they are
     * replaced by `?`, and SQL the redactor cannot parse is not sent at all.
     */
    private function sqlPreview(QueryRequest $request): string
    {
        $mode = ChatSqlMode::fromConfig(config('queryproxy.chat_include_sql'));

        if ($mode === ChatSqlMode::None) {
            return '(SQL preview disabled — review in QueryProxy)';
        }

        $sql = (string) $request->sql_prepared;

        if ($mode === ChatSqlMode::LiteralsRedacted) {
            $sql = app(SqlRedactor::class)->redact($sql, $request->connection?->driver);

            if ($sql === null) {
                return '(SQL hidden — review in QueryProxy)';
            }
        }

        return (string) str($sql)->limit(400);
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
    private function teamsSubmittedCard(QueryRequest $request, string $token, ?string $lead = null): array
    {
        return [
            'queryproxy' => [
                'request_id' => $request->id,
                'action_token' => $token,
            ],
            'type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => ($lead ? $lead.' ' : '').sprintf('New query request #%d%s', $request->id, $request->is_ddl ? ' (DDL)' : ''),
            'themeColor' => '6366F1',
            'title' => sprintf(
                'New %s request #%d%s',
                strtoupper($request->type->value),
                $request->id,
                $request->is_ddl ? ' · DDL' : '',
            ),
            'sections' => [
                [
                    'facts' => [
                        ['name' => 'Requester', 'value' => $request->requester->name],
                        ['name' => 'Connection', 'value' => $request->connection->name],
                        ['name' => 'Statements', 'value' => (string) $request->statement_count],
                    ],
                    'text' => ($lead ? $lead."\n\n" : '').'```'.$this->sqlPreview($request).'```',
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

    /**
     * Teams Workflows (Power Automate) Adaptive Card 1.5: informational with a
     * deep link. Approval from Teams still goes through the HMAC-signed action
     * endpoint (ADR-004), called by the flow; the single-use token rides in the
     * same top-level `queryproxy` envelope the legacy card uses, which the
     * flow reads from the trigger body and Teams never renders.
     *
     * @return array<string, mixed>
     */
    private function teamsAdaptiveCard(QueryRequest $request, string $token, ?string $lead = null): array
    {
        $title = sprintf(
            'New %s request #%d%s',
            strtoupper($request->type->value),
            $request->id,
            $request->is_ddl ? ' · DDL' : '',
        );

        $body = [];

        if ($lead) {
            $body[] = ['type' => 'TextBlock', 'text' => $lead, 'wrap' => true, 'color' => 'Attention'];
        }

        $body[] = ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true];
        $body[] = [
            'type' => 'FactSet',
            'facts' => [
                ['title' => 'Requester', 'value' => $request->requester->name],
                ['title' => 'Connection', 'value' => $request->connection->name],
                ['title' => 'Statements', 'value' => (string) $request->statement_count],
            ],
        ];
        // A TextRun is not parsed as markdown (a TextBlock is), so `a*b, c*d`
        // reaches the reviewer exactly as written.
        $body[] = [
            'type' => 'RichTextBlock',
            'inlines' => [[
                'type' => 'TextRun',
                'text' => $this->sqlPreview($request),
                'fontType' => 'Monospace',
            ]],
        ];

        return [
            'queryproxy' => [
                'request_id' => $request->id,
                'action_token' => $token,
            ],
        ] + $this->adaptiveMessage($body, sprintf(
            'New query request #%d%s',
            $request->id,
            $request->is_ddl ? ' (DDL)' : '',
        ), [
            [
                'type' => 'Action.OpenUrl',
                'title' => 'Review in QueryProxy',
                'url' => route('requests.show', $request),
            ],
        ]);
    }

    /**
     * The Workflows "post card" envelope around an Adaptive Card 1.5.
     *
     * @param  list<array<string, mixed>>  $body
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function adaptiveMessage(array $body, string $fallbackText, array $actions = []): array
    {
        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.5',
            'fallbackText' => $fallbackText,
            'body' => $body,
            'msteams' => ['width' => 'Full'],
        ];

        if ($actions !== []) {
            $card['actions'] = $actions;
        }

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => $card,
                ],
            ],
        ];
    }
}
