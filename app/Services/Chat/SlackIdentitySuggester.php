<?php

namespace App\Services\Chat;

use App\Enums\ChatProvider;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\SlackIdentitySuggestion;
use Illuminate\Support\Facades\Log;

/**
 * Looks the team's QueryProxy users up in the Slack workspace by e-mail
 * address (users.lookupByEmail) and stores what it finds as suggestions
 * (ADR-027, decision D-18 b).
 *
 * It never creates a ChatIdentity: an e-mail address match is a hint, not
 * proof that the Slack member is that person, and a chat identity is what
 * lets a Slack click act as a QueryProxy user. An admin confirms each
 * suggestion in the web UI.
 */
class SlackIdentitySuggester
{
    /**
     * Bounds the Slack calls of one batch. SuggestSlackIdentities queues the
     * next batch from the cursor this one ends at, so a large team is covered
     * in several runs.
     */
    public const MAX_LOOKUPS = 200;

    public function __construct(private readonly SlackClient $slack) {}

    /**
     * @return int the number of suggestions stored by one batch from the start
     */
    public function suggestFor(ChatIntegration $integration): int
    {
        return $this->suggestBatch($integration)['stored'];
    }

    /**
     * Look up one batch of users, in id order, after $afterUserId.
     *
     * @return array{stored: int, cursor: int, more: bool, rateLimited: bool} `cursor` is the last user looked
     *                                                                        at; `more` says a full batch was read, so another may follow
     */
    public function suggestBatch(ChatIntegration $integration, int $afterUserId = 0, int $limit = self::MAX_LOOKUPS): array
    {
        $result = ['stored' => 0, 'cursor' => $afterUserId, 'more' => false, 'rateLimited' => false];

        if ($integration->provider !== ChatProvider::Slack || blank($integration->bot_token) || blank($integration->slack_team_id)) {
            return $result;
        }

        $workspaceId = $integration->slack_team_id;

        $linkedUserIds = ChatIdentity::query()->where('provider', ChatProvider::Slack->value)->pluck('user_id');
        $linkedSlackIds = ChatIdentity::query()->where('provider', ChatProvider::Slack->value)->pluck('external_id');
        // Dismissed rows are left out: a user whose suggestion was rejected is
        // looked up again, because only the rejected pair (user, Slack member)
        // stays rejected. If the address now points at another member, that one
        // is proposed.
        $alreadySuggested = SlackIdentitySuggestion::query()
            ->where('slack_team_id', $workspaceId)
            ->whereNull('dismissed_at')
            ->pluck('user_id');

        $users = $integration->team->users()
            ->active()
            ->whereNotIn('users.id', $linkedUserIds)
            ->whereNotIn('users.id', $alreadySuggested)
            ->where('users.id', '>', $afterUserId)
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        $result['more'] = $users->count() === $limit;

        foreach ($users as $user) {
            try {
                $member = $this->slack->lookupUserByEmail($integration->bot_token, $user->email);
            } catch (SlackApiException $e) {
                Log::warning('QueryProxy: Slack identity lookup failed', [
                    'team_id' => $integration->team_id,
                    'error' => $e->slackError,
                ]);

                // Revoked token, missing scope: none improves with the next
                // user. A rate limit does, later: the job retries from here.
                $result['rateLimited'] = $e->slackError === 'ratelimited';
                $result['more'] = false;

                break;
            }

            $result['cursor'] = $user->id;

            // Bots and deactivated members are not people to link; a member
            // already linked to someone else is not suggested for a second user.
            if ($member === null || $member['is_bot'] || $member['deleted'] || $linkedSlackIds->contains($member['id'])) {
                continue;
            }

            // The pair an admin rejected is not proposed again. Looked up after
            // the Slack call, so a rejection that landed meanwhile counts too.
            $dismissed = SlackIdentitySuggestion::query()
                ->where('user_id', $user->id)
                ->where('slack_team_id', $workspaceId)
                ->whereNotNull('dismissed_at')
                ->where('slack_user_id', $member['id'])
                ->exists();

            if ($dismissed) {
                continue;
            }

            SlackIdentitySuggestion::query()->updateOrCreate(
                ['user_id' => $user->id, 'slack_team_id' => $workspaceId],
                [
                    'team_id' => $integration->team_id,
                    'slack_user_id' => $member['id'],
                    'matched_email' => $user->email,
                    'status' => SlackIdentitySuggestion::PENDING,
                    // A different member than the rejected one starts over.
                    'dismissed_at' => null,
                ],
            );

            $result['stored']++;
        }

        return $result;
    }
}
