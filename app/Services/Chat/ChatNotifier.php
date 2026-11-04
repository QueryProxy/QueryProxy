<?php

namespace App\Services\Chat;

use App\Enums\AuditEvent;
use App\Enums\ChatProvider;
use App\Enums\ChatSqlMode;
use App\Enums\GrantRequestStatus;
use App\Enums\QueryRequestStatus;
use App\Enums\TeamRole;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\ConnectionGrant;
use App\Models\ConnectionGrantRequest;
use App\Models\QueryRequest;
use App\Services\Sql\SqlRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts request lifecycle messages to the team's configured chat channels.
 * Failures are logged, never thrown — chat being down must not break the flow.
 *
 * A Slack integration with an installed app (bot token and channel, ADR-027)
 * posts through chat.postMessage, remembers the channel and `ts` of each
 * request's card and updates that card as the request moves on. Every other
 * integration keeps the incoming webhook, which cannot update a message.
 */
class ChatNotifier
{
    /**
     * Teams webhook hosts that belong to Workflows (Power Automate), which
     * expects an Adaptive Card message. Every other Teams host is a legacy
     * Office 365 connector URL and keeps receiving the MessageCard.
     */
    public const WORKFLOWS_HOSTS = ['*.powerplatform.com', '*.logic.azure.com'];

    public function __construct(private readonly SlackClient $slack) {}

    public function requestSubmitted(QueryRequest $request): void
    {
        $this->postRequestCard($request);

        foreach ($this->integrations($request) as $integration) {
            if ($integration->usesSlackBot() && $integration->dm_approvers) {
                $this->directMessageApprovers($integration, $request);
            }
        }
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

            if ($integration->usesSlackBot() && $this->postCardAsBot($integration, $request, $payload)) {
                continue;
            }

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
            // The card shows the decision itself; a follow-up message would
            // only repeat it. Webhooks cannot update, so they get the text.
            if (! $this->updateCard($integration, $request)) {
                $this->postTextTo($integration, $text);
            }
        }
    }

    /** The approved request is picked up by a worker. Slack app only. */
    public function requestStarted(QueryRequest $request): void
    {
        $this->updateCards($request, QueryRequestStatus::Running);
    }

    /** A pending or running request was cancelled. Slack app only. */
    public function requestCancelled(QueryRequest $request): void
    {
        $this->updateCards($request);
    }

    /**
     * The run ended (completed or failed): update the card and reply in its
     * thread with the outcome. Slack app only; the webhook mode has no
     * message to update.
     */
    public function requestFinished(QueryRequest $request): void
    {
        $this->updateCards($request);

        $this->guarded('posting the Slack thread reply', $request, function () use ($request) {
            foreach ($this->integrations($request) as $integration) {
                $this->replyInThread($integration, $request);
            }
        });
    }

    /**
     * A developer asked for time-bound access (ADR-025). Text only, with no
     * action buttons: the DBA decides in the web UI. The free-text reason is
     * left out on purpose; the DBA reads it in the app. Names are escaped for
     * Slack in postText().
     */
    public function grantRequested(ConnectionGrantRequest $grantRequest): void
    {
        $this->postText($grantRequest->team_id, sprintf(
            '🔑 %s asked for %s access to %s for %s. Decide in QueryProxy.',
            $grantRequest->requester->name,
            $grantRequest->access_level->value,
            $grantRequest->connection->name,
            $grantRequest->durationLabel(),
        ));
    }

    /**
     * The DBA's decision on an access request (ADR-025). $grant is the grant
     * as stored after an approval, so the message states what is in force
     * (an earlier, wider grant is never shortened by an approval).
     */
    public function grantDecided(ConnectionGrantRequest $grantRequest, ?ConnectionGrant $grant = null): void
    {
        $approved = $grantRequest->status === GrantRequestStatus::Approved;

        $this->postText($grantRequest->team_id, sprintf(
            '%s Access request #%d (%s, %s) by %s was %s by %s%s.',
            $approved ? '✅' : '⛔',
            $grantRequest->id,
            $grantRequest->connection->name,
            $grantRequest->access_level->value,
            $grantRequest->requester->name,
            $approved ? 'approved' : 'rejected',
            $grantRequest->decider?->name ?? 'a reviewer',
            $approved && $grant !== null ? '; now in force: '.$grant->describe() : '',
        ));
    }

    /**
     * One plain-text message to every enabled integration of the team. Slack
     * reads `<!channel>` and similar in a message, so the three characters
     * Slack documents as control characters are escaped there; a user name
     * cannot raise a mention.
     */
    private function postText(int $teamId, string $text): void
    {
        $integrations = ChatIntegration::query()
            ->where('team_id', $teamId)
            ->where('enabled', true)
            ->get();

        foreach ($integrations as $integration) {
            $this->postTextTo($integration, $text);
        }
    }

    /** One plain-text message to one integration, through the bot when it has one. */
    private function postTextTo(ChatIntegration $integration, string $text): void
    {
        if ($integration->usesSlackBot()) {
            try {
                $this->slack->postMessage($integration->bot_token, $integration->slack_channel_id, ['text' => $this->slackEscape($text)]);

                return;
            } catch (SlackApiException $e) {
                $this->botFailed($integration, $e);
            }
        }

        $payload = match (true) {
            $integration->provider === ChatProvider::Slack => ['text' => $this->slackEscape($text)],
            $this->usesAdaptiveCard($integration) => $this->adaptiveMessage([
                ['type' => 'TextBlock', 'text' => $text, 'wrap' => true],
            ], $text),
            default => ['type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => $text, 'text' => $text],
        };

        $this->post($integration, $payload);
    }

    private function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * @return Collection<int, ChatIntegration>
     */
    private function integrations(QueryRequest $request): Collection
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
        // An app-only Slack installation has no webhook to fall back to.
        if (blank($integration->webhook_url)) {
            return;
        }

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

    /**
     * Post the card through the bot and remember where it landed, so later
     * status changes can update it. False when Slack refused: the caller then
     * falls back to the webhook, if the integration has one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postCardAsBot(ChatIntegration $integration, QueryRequest $request, array $payload): bool
    {
        try {
            $posted = $this->slack->postMessage($integration->bot_token, $integration->slack_channel_id, $payload);
        } catch (SlackApiException $e) {
            $this->botFailed($integration, $e);

            return false;
        }

        if ($posted['ts'] !== '') {
            $earlier = ['channel' => $request->slack_channel, 'ts' => $request->slack_ts];

            // The newest card is the one that has working buttons: it is the
            // one later status changes update.
            $request->forceFill(['slack_channel' => $posted['channel'], 'slack_ts' => $posted['ts']])->saveQuietly();

            $this->retireEarlierCard($integration, $request, $earlier);
        }

        return true;
    }

    /**
     * A reminder or a renewed card is posted as a new message. The one before
     * it would keep its buttons and a stale status for good, because only the
     * newest message is updated from now on: take its buttons away and point
     * to the new one. Best effort.
     *
     * @param  array{channel: string|null, ts: string|null}  $earlier
     */
    private function retireEarlierCard(ChatIntegration $integration, QueryRequest $request, array $earlier): void
    {
        if (blank($earlier['channel']) || blank($earlier['ts']) || $earlier['ts'] === $request->slack_ts) {
            return;
        }

        try {
            $this->slack->updateMessage(
                $integration->bot_token,
                $earlier['channel'],
                $earlier['ts'],
                $this->slackStatusMessage($request, QueryRequestStatus::Pending, '🔄', 'Moved: use the newer message for this request.'),
            );
        } catch (SlackApiException $e) {
            $this->botFailed($integration, $e);
        }
    }

    /** Update the card of the request on every Slack app integration of its team. */
    private function updateCards(QueryRequest $request, ?QueryRequestStatus $as = null): void
    {
        $this->guarded('updating the Slack card', $request, function () use ($request, $as) {
            foreach ($this->integrations($request) as $integration) {
                $this->updateCard($integration, $request, $as);
            }
        });
    }

    /**
     * chat.update on the stored message of the request. False when this
     * integration cannot (webhook mode, no stored message) or Slack refused.
     */
    private function updateCard(ChatIntegration $integration, QueryRequest $request, ?QueryRequestStatus $as = null): bool
    {
        if (! $this->canUpdateCard($integration, $request)) {
            return false;
        }

        try {
            $this->slack->updateMessage(
                $integration->bot_token,
                $request->slack_channel,
                $request->slack_ts,
                $this->slackStatusMessage($request, $as ?? $request->status),
            );

            return true;
        } catch (SlackApiException $e) {
            $this->botFailed($integration, $e);

            return false;
        }
    }

    private function canUpdateCard(ChatIntegration $integration, QueryRequest $request): bool
    {
        return $integration->provider === ChatProvider::Slack
            && filled($integration->bot_token)
            && filled($request->slack_channel)
            && filled($request->slack_ts);
    }

    /**
     * The card after a status change: the buttons are gone, the status is
     * stated. The SQL preview follows the same chat_include_sql mode as the
     * original card.
     *
     * @return array<string, mixed>
     */
    private function slackStatusMessage(QueryRequest $request, QueryRequestStatus $status, ?string $emojiOverride = null, ?string $summaryOverride = null): array
    {
        [$emoji, $summary] = $this->statusSummary($request, $status);
        $emoji = $emojiOverride ?? $emoji;
        $summary = $summaryOverride ?? $summary;

        return [
            'text' => sprintf('Query request #%d: %s', $request->id, $summary),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $this->slackEscape(sprintf(
                            "*%s request #%d*%s\n*Requester:* %s\n*Connection:* %s%s",
                            strtoupper($request->type->value),
                            $request->id,
                            $request->is_ddl ? ' · *DDL*' : '',
                            $request->requester->name,
                            $request->connection->name,
                            $request->title ? "\n*Title:* {$request->title}" : '',
                        )),
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => '```'.$this->slackEscape($this->sqlPreview($request)).'```'],
                ],
                [
                    'type' => 'context',
                    'elements' => [['type' => 'mrkdwn', 'text' => $emoji.' '.$this->slackEscape($summary)]],
                ],
                [
                    'type' => 'actions',
                    'elements' => [[
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open in QueryProxy'],
                        'action_id' => 'queryproxy_open',
                        'url' => route('requests.show', $request),
                    ]],
                ],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} emoji and the status sentence
     */
    private function statusSummary(QueryRequest $request, QueryRequestStatus $status): array
    {
        $reviewer = $request->reviewer?->name ?? 'a reviewer';

        return match ($status) {
            QueryRequestStatus::Pending => ['⏳', 'Waiting for review.'],
            QueryRequestStatus::Approved, QueryRequestStatus::Queued => ['✅', "Approved by {$reviewer} — queued for execution."],
            QueryRequestStatus::Rejected => ['⛔', "Rejected by {$reviewer}."],
            QueryRequestStatus::Cancelled => ['🚫', 'Cancelled.'],
            QueryRequestStatus::Running => ['⚙️', 'Running.'],
            QueryRequestStatus::Completed => ['✅', 'Completed.'],
            QueryRequestStatus::Failed => ['❌', 'Execution failed.'],
        };
    }

    /**
     * The outcome in the thread of the card: status, row count, duration and
     * the web link. Never SQL text, result data or the error message.
     */
    private function replyInThread(ChatIntegration $integration, QueryRequest $request): void
    {
        if (! $this->canUpdateCard($integration, $request)
            || ! in_array($request->status, [QueryRequestStatus::Completed, QueryRequestStatus::Failed], true)) {
            return;
        }

        $completed = $request->status === QueryRequestStatus::Completed;
        $rows = $completed ? ($request->result_row_count ?? $request->affected_rows) : null;

        $parts = [$completed ? '✅ Completed' : '❌ Failed'];

        if ($rows !== null) {
            $parts[] = number_format((int) $rows).' '.((int) $rows === 1 ? 'row' : 'rows');
        }

        if ($request->duration_ms !== null) {
            $parts[] = $request->duration_ms < 1000
                ? $request->duration_ms.' ms'
                : number_format($request->duration_ms / 1000, 1).' s';
        }

        $parts[] = sprintf('<%s|Open in QueryProxy>', route('requests.show', $request));

        $this->guarded('posting the Slack thread reply', $request, function () use ($integration, $request, $parts) {
            try {
                $this->slack->postMessage(
                    $integration->bot_token,
                    $request->slack_channel,
                    ['text' => implode(' · ', $parts)],
                    $request->slack_ts,
                );
            } catch (SlackApiException $e) {
                $this->botFailed($integration, $e);
            }
        });
    }

    /**
     * Direct message to the team's DBAs who have a Slack identity (the opt-in
     * of the integration). Text and a link only: the decision is taken on the
     * channel card or in the web UI.
     */
    private function directMessageApprovers(ChatIntegration $integration, QueryRequest $request): void
    {
        $this->guarded('sending Slack direct messages', $request, function () use ($integration, $request) {
            $approverIds = $request->team->users()
                ->wherePivot('role', TeamRole::Dba->value)
                ->where('users.id', '!=', $request->user_id)
                ->whereNull('users.suspended_at')
                ->pluck('users.id');

            $slackIds = ChatIdentity::query()
                ->where('provider', ChatProvider::Slack->value)
                ->whereIn('user_id', $approverIds)
                ->pluck('external_id');

            $text = sprintf(
                '🔔 New %s request #%d%s from %s on %s needs your review.',
                strtoupper($request->type->value),
                $request->id,
                $request->is_ddl ? ' (DDL)' : '',
                $request->requester->name,
                $request->connection->name,
            );

            $message = [
                'text' => $this->slackEscape($text),
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->slackEscape($text)]],
                    [
                        'type' => 'actions',
                        'elements' => [[
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Review in QueryProxy'],
                            'action_id' => 'queryproxy_open',
                            'url' => route('requests.show', $request),
                        ]],
                    ],
                ],
            ];

            foreach ($slackIds as $slackId) {
                try {
                    // Posting to a member id opens (or reuses) the direct message.
                    $this->slack->postMessage($integration->bot_token, $slackId, $message);
                } catch (SlackApiException $e) {
                    $this->botFailed($integration, $e);

                    // One member outside this workspace must not stop the rest;
                    // a dead token does.
                    if ($e->isDeadToken()) {
                        return;
                    }
                }
            }
        });
    }

    /**
     * A Slack call failed. A token Slack no longer accepts ends the
     * installation (the webhook mode, if any, goes on); everything else is
     * logged and the notification is lost, as for the webhook.
     */
    private function botFailed(ChatIntegration $integration, SlackApiException $e): void
    {
        Log::warning('QueryProxy: Slack app call failed', [
            'team_id' => $integration->team_id,
            'method' => $e->method,
            'error' => $e->slackError,
        ]);

        if (! $e->isDeadToken()) {
            return;
        }

        $workspace = $integration->slack_team_id;

        $integration->clearSlackInstallation();

        audit()->record(AuditEvent::SlackUninstalled, team: $integration->team, metadata: [
            'slack_team_id' => $workspace,
            'reason' => 'token_rejected',
        ], system: true);
    }

    /**
     * Lifecycle notifications run inside the approval and execution paths:
     * whatever goes wrong here is logged and goes no further.
     */
    private function guarded(string $what, QueryRequest $request, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::warning("QueryProxy: {$what} failed", [
                'query_request_id' => $request->id,
                'exception' => $e::class,
            ]);
        }
    }
}
