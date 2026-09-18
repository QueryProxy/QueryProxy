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
 * Generic HMAC-verified action endpoint for Microsoft Teams automations
 * (Power Automate flows / custom automations).
 *
 * Expected JSON body:
 *   { "action": "approve"|"reject", "request_id": 123,
 *     "actor_id": "<AAD object id>", "token": "<action token>",
 *     "reason": "optional for reject" }
 *
 * `token` is the single-use action token QueryProxy put in the `queryproxy`
 * envelope of the card it posted for this request. The HMAC alone proves only
 * that the caller knows the shared secret; the token proves the call answers a
 * card we actually sent, and it can be spent exactly once.
 *
 * The actor is resolved through the admin-managed ChatIdentity mapping, never
 * from a self-declared email, and must belong to the integration's team. That
 * narrows a secret holder to acting *within* the team on a request they were
 * genuinely notified about — it cannot make `actor_id` itself trustworthy, so
 * the policy still has the last word on role and self-review.
 */
class TeamsActionController extends Controller
{
    /** Vague on purpose — see SlackInteractionController::UNVERIFIED_ACTION. */
    private const UNVERIFIED_ACTION = 'This approval action could not be verified. Decide on the request in QueryProxy.';

    public function __invoke(Request $request, ApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'request_id' => ['required', 'integer'],
            'actor_id' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var ChatIntegration $integration */
        $integration = $request->attributes->get('chat_integration');

        $queryRequest = QueryRequest::query()
            ->where('team_id', $integration->team_id)
            ->find($validated['request_id']);

        if (! $queryRequest) {
            return response()->json(['ok' => false, 'message' => 'Query request not found.'], 404);
        }

        if (! ChatApprovalToken::isValidFor($queryRequest, $validated['token'])) {
            return response()->json(['ok' => false, 'message' => self::UNVERIFIED_ACTION], 422);
        }

        $identity = ChatIdentity::query()
            ->where('provider', 'teams')
            ->where('external_id', $validated['actor_id'])
            ->first();

        if (! $identity) {
            return response()->json([
                'ok' => false,
                'message' => 'This Teams user is not linked to a QueryProxy user. Ask an admin to set the Teams ID in Admin → Users.',
            ], 422);
        }

        $reviewer = $identity->user;

        // Defence in depth: an identity mapped to someone outside the team
        // must not reach the policy at all.
        if (! $reviewer->belongsToTeam($integration->team)) {
            return response()->json([
                'ok' => false,
                'message' => 'This user may not decide on the request.',
            ], 403);
        }

        try {
            if ($validated['action'] === 'approve') {
                $approvals->approve($queryRequest, $reviewer, 'teams');
                $message = "Request #{$queryRequest->id} approved and queued.";
            } else {
                $reason = $validated['reason'] ?? "Rejected via Teams by {$reviewer->name}.";
                $approvals->reject($queryRequest, $reviewer, $reason, 'teams');
                $message = "Request #{$queryRequest->id} rejected.";
            }
        } catch (AuthorizationException) {
            return response()->json([
                'ok' => false,
                'message' => 'This user may not decide on the request (wrong role or own request).',
            ], 403);
        }

        // Burn the token only after a decision actually landed, so a call the
        // policy turned away does not spend the card's one chance to act.
        if (! ChatApprovalToken::consume($queryRequest, $validated['token'])) {
            return response()->json(['ok' => false, 'message' => self::UNVERIFIED_ACTION], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }
}
