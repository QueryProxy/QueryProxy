<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Slack Block Kit callback endpoint.
 *
 * The HMAC middleware only proves that *someone holding the team's signing
 * secret* sent this body — it says nothing about who the body claims to be.
 * `payload.user.id` is caller-supplied, and a Slack member ID is public inside
 * a workspace, so on its own it is a name tag, not a credential. Three checks
 * sit between that claim and a decision:
 *
 *  - the button value must carry a live, unused action token minted when the
 *    message was posted (so the caller must have seen our message, and each
 *    message decides exactly once);
 *  - the Slack ID must map to a ChatIdentity an admin created;
 *  - that user must belong to the integration's team.
 *
 * The QueryRequestPolicy then has the last word on role and self-review.
 */
class SlackInteractionController extends Controller
{
    /**
     * Deliberately vague: a caller who cannot produce a valid token should not
     * learn whether the token was unknown, expired, already spent, or whether
     * the identity they claimed exists at all.
     */
    private const UNVERIFIED_ACTION = 'This approval action could not be verified. Open the request in QueryProxy to decide.';

    public function __invoke(Request $request, ApprovalService $approvals): JsonResponse
    {
        $payload = json_decode((string) $request->input('payload'), true);

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'block_actions') {
            return response()->json(['text' => 'Unsupported payload.'], 400);
        }

        /** @var ChatIntegration $integration */
        $integration = $request->attributes->get('chat_integration');

        $action = $payload['actions'][0]['value'] ?? '';
        $slackUserId = $payload['user']['id'] ?? '';

        if (! preg_match('/^(approve|reject):(\d+):([A-Za-z0-9]+)$/', $action, $matches)) {
            // A decision-shaped value without a token is either a message from
            // before tokens existed or a hand-rolled forgery; both are refused.
            if (preg_match('/^(approve|reject):/', (string) $action)) {
                return $this->ephemeral(self::UNVERIFIED_ACTION);
            }

            // Not an actionable button (e.g. the "Open in QueryProxy" link).
            return response()->json([]);
        }

        [, $verb, $requestId, $token] = $matches;

        $queryRequest = QueryRequest::query()
            ->where('team_id', $integration->team_id)
            ->find((int) $requestId);

        if (! $queryRequest) {
            return $this->ephemeral('This query request no longer exists.');
        }

        if (! ChatApprovalToken::isValidFor($queryRequest, $token)) {
            return $this->ephemeral(self::UNVERIFIED_ACTION);
        }

        $identity = ChatIdentity::query()
            ->where('provider', 'slack')
            ->where('external_id', $slackUserId)
            ->first();

        if (! $identity) {
            return $this->ephemeral(
                'Your Slack account is not linked to a QueryProxy user. '
                .'Ask an admin to set your Slack member ID in Admin → Users.',
            );
        }

        $reviewer = $identity->user;

        // Defence in depth: the policy already requires the DBA role in this
        // team, but an identity mapped to an outsider must never even reach it.
        if (! $reviewer->belongsToTeam($integration->team)) {
            return $this->ephemeral('You are not allowed to decide on this request.');
        }

        try {
            if ($verb === 'approve') {
                $approvals->approve($queryRequest, $reviewer, 'slack');
                $outcome = "✅ Request #{$queryRequest->id} approved by {$reviewer->name} — queued for execution.";
            } else {
                $approvals->reject($queryRequest, $reviewer, "Rejected via Slack by {$reviewer->name}.", 'slack');
                $outcome = "⛔ Request #{$queryRequest->id} rejected by {$reviewer->name}.";
            }
        } catch (AuthorizationException) {
            return $this->ephemeral('You are not allowed to decide on this request (wrong team role, or it is your own request).');
        }

        // Burn the token only once a decision actually landed, so a click the
        // policy turned away does not cost the channel its one chance to act.
        if (! ChatApprovalToken::consume($queryRequest, $token)) {
            return $this->ephemeral(self::UNVERIFIED_ACTION);
        }

        // Replace the original message so the buttons disappear.
        return response()->json([
            'replace_original' => true,
            'text' => $outcome,
        ]);
    }

    private function ephemeral(string $text): JsonResponse
    {
        return response()->json([
            'response_type' => 'ephemeral',
            'replace_original' => false,
            'text' => $text,
        ]);
    }
}
