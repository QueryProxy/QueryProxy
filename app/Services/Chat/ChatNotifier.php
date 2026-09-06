<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Enums\QueryRequestStatus;
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
            $payload = $integration->provider === ChatProvider::Slack
                ? $this->slackSubmittedMessage($request)
                : $this->teamsSubmittedCard($request);

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
            Http::timeout(5)->post($integration->webhook_url, $payload)->throw();
        } catch (Throwable $e) {
            Log::warning('QueryProxy: chat notification failed', [
                'team_id' => $integration->team_id,
                'provider' => $integration->provider->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Slack Block Kit message with interactive Approve / Reject buttons.
     * Button clicks arrive at /webhooks/slack/interactions (HMAC-verified).
     *
     * @return array<string, mixed>
     */
    private function slackSubmittedMessage(QueryRequest $request): array
    {
        $sqlPreview = str($request->sql_prepared)->limit(400);

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
                            'value' => "approve:{$request->id}",
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
                            'value' => "reject:{$request->id}",
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
     * Teams MessageCard: informational with a deep link (interactive approval
     * for Teams runs through the generic HMAC action endpoint, e.g. from a
     * Power Automate flow).
     *
     * @return array<string, mixed>
     */
    private function teamsSubmittedCard(QueryRequest $request): array
    {
        return [
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
                    'text' => '```'.str($request->sql_prepared)->limit(400).'```',
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
