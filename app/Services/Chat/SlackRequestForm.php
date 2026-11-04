<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\Connection;
use App\Models\User;
use App\Services\Approvals\ApprovalNotifier;
use App\Services\Requests\RequestSubmissionException;
use App\Services\Requests\RequestSubmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * The `/queryproxy` modal (ADR-027): who may use it, what it offers, and what
 * a submission does.
 *
 * Nothing in a payload is trusted beyond the Slack signature: the Slack member
 * must map to an active QueryProxy user through a ChatIdentity an admin
 * created, the chosen connection must belong to a team installed in the
 * member's workspace, and the submission then goes through the same
 * RequestSubmissionService as the web UI (`channel=slack`), so the connection
 * policy, the SQL guard and the approval flow are unchanged.
 */
class SlackRequestForm
{
    public const CALLBACK_ID = 'queryproxy_submit';

    /** Slack allows at most 100 options in a select. */
    private const MAX_OPTIONS = 100;

    public const BLOCK_CONNECTION = 'connection_block';

    public const BLOCK_SQL = 'sql_block';

    public const BLOCK_REASON = 'reason_block';

    public function __construct(private readonly RequestSubmissionService $submissions) {}

    /**
     * Installations (one per team) of this Slack workspace that can post.
     *
     * @return Collection<int, ChatIntegration>
     */
    public function integrationsFor(string $workspaceId): Collection
    {
        if ($workspaceId === '') {
            return new Collection;
        }

        return ChatIntegration::query()
            ->enabledFor(ChatProvider::Slack)
            ->where('slack_team_id', $workspaceId)
            ->whereNotNull('bot_token')
            ->with('team')
            ->get();
    }

    /**
     * Suspended and soft-deleted users do not resolve: their chat identity is dead.
     */
    public function resolveUser(string $slackUserId): ?User
    {
        if ($slackUserId === '') {
            return null;
        }

        return ChatIdentity::query()
            ->where('provider', ChatProvider::Slack->value)
            ->where('external_id', $slackUserId)
            ->whereHas('user', fn ($query) => $query->active())
            ->first()
            ?->user;
    }

    /**
     * Connections the user may submit through, across the teams installed in
     * the workspace.
     *
     * @param  Collection<int, ChatIntegration>  $integrations
     * @return Collection<int, Connection>
     */
    public function usableConnections(User $user, Collection $integrations): Collection
    {
        $connections = Connection::query()
            ->whereIn('team_id', $integrations->pluck('team_id'))
            ->with('team')
            ->orderBy('name')
            ->get()
            ->filter(fn (Connection $connection) => Gate::forUser($user)->allows('use', $connection))
            ->values();

        return $connections->take(self::MAX_OPTIONS);
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return array<string, mixed>
     */
    public function view(Collection $connections): array
    {
        $showTeam = $connections->pluck('team_id')->unique()->count() > 1;

        $options = $connections->map(fn (Connection $connection) => [
            'text' => [
                'type' => 'plain_text',
                'text' => Str::limit($showTeam ? "{$connection->team->name} / {$connection->name}" : $connection->name, 70),
            ],
            'value' => (string) $connection->id,
        ])->all();

        return [
            'type' => 'modal',
            'callback_id' => self::CALLBACK_ID,
            'title' => ['type' => 'plain_text', 'text' => 'New query request'],
            'submit' => ['type' => 'plain_text', 'text' => 'Submit'],
            'close' => ['type' => 'plain_text', 'text' => 'Cancel'],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => self::BLOCK_CONNECTION,
                    'label' => ['type' => 'plain_text', 'text' => 'Connection'],
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'connection',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Choose a connection'],
                        'options' => $options,
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => self::BLOCK_SQL,
                    'label' => ['type' => 'plain_text', 'text' => 'SQL'],
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'sql',
                        'multiline' => true,
                        'max_length' => 3000,
                    ],
                ],
                [
                    'type' => 'input',
                    'block_id' => self::BLOCK_REASON,
                    'optional' => true,
                    'label' => ['type' => 'plain_text', 'text' => 'Reason'],
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'reason',
                        'multiline' => true,
                        'max_length' => 1000,
                    ],
                ],
            ],
        ];
    }

    /**
     * Handle a `view_submission` payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the response body: empty to close the modal, or
     *                              `response_action: errors` to keep it open
     */
    public function submit(array $payload): array
    {
        $values = $payload['view']['state']['values'] ?? [];
        $workspaceId = (string) ($payload['team']['id'] ?? '');
        $slackUserId = (string) ($payload['user']['id'] ?? '');

        $user = $this->resolveUser($slackUserId);

        if ($user === null) {
            return $this->errors(self::BLOCK_CONNECTION, 'Your Slack account is not linked to a QueryProxy user. Ask an admin to link it.');
        }

        $connectionId = $values[self::BLOCK_CONNECTION]['connection']['selected_option']['value'] ?? null;
        $sql = $values[self::BLOCK_SQL]['sql']['value'] ?? null;
        $reason = $values[self::BLOCK_REASON]['reason']['value'] ?? null;

        if (! is_string($connectionId) || ! ctype_digit($connectionId) || ! is_string($sql) || trim($sql) === '') {
            return $this->errors(self::BLOCK_SQL, 'Choose a connection and enter the SQL.');
        }

        $connection = Connection::query()->find((int) $connectionId);
        $integrations = $this->integrationsFor($workspaceId);

        // The connection must be one this workspace is installed for, and the
        // member must be allowed to use it: the form only offered such
        // connections, but a payload can name any id.
        if ($connection === null
            || ! $integrations->contains('team_id', $connection->team_id)
            || ! Gate::forUser($user)->allows('use', $connection)) {
            return $this->errors(self::BLOCK_CONNECTION, 'You cannot submit queries through this connection.');
        }

        // Slack shows an error when it gets no answer in time and leaves the
        // modal open, and the member then presses Submit again. One modal
        // (view id) files one request: a repeat of an accepted submission just
        // closes the modal.
        $viewId = $payload['view']['id'] ?? null;
        $lockKey = is_string($viewId) && $viewId !== '' ? 'slack-view-submitted:'.$viewId : null;

        if ($lockKey !== null && ! Cache::add($lockKey, true, now()->addMinutes(10))) {
            return [];
        }

        try {
            // The approval card and the direct messages go out after the answer.
            ApprovalNotifier::deferringChat(fn () => $this->submissions->submit(
                $user,
                $connection,
                $sql,
                is_string($reason) ? $reason : null,
                'slack',
            ));
        } catch (RequestSubmissionException $e) {
            $this->releaseLock($lockKey);

            return $this->errors(self::BLOCK_SQL, Str::limit(implode(' ', $e->violations) ?: 'The SQL guard refused this statement.', 300));
        } catch (AuthorizationException $e) {
            $this->releaseLock($lockKey);

            return $this->errors(self::BLOCK_SQL, Str::limit($e->getMessage(), 300));
        } catch (Throwable $e) {
            $this->releaseLock($lockKey);

            throw $e;
        }

        return [];
    }

    private function releaseLock(?string $key): void
    {
        if ($key !== null) {
            Cache::forget($key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(string $blockId, string $message): array
    {
        return ['response_action' => 'errors', 'errors' => [$blockId => $message]];
    }
}
