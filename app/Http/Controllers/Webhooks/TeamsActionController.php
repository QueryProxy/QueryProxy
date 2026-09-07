<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
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
 *     "actor_id": "<AAD object id>", "reason": "optional for reject" }
 *
 * The actor is resolved through the admin-managed ChatIdentity mapping, never
 * from a self-declared email: whoever holds the shared HMAC secret must not
 * be able to approve as an arbitrary user.
 */
class TeamsActionController extends Controller
{
    public function __invoke(Request $request, ApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'request_id' => ['required', 'integer'],
            'actor_id' => ['required', 'string', 'max:255'],
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

        return response()->json(['ok' => true, 'message' => $message]);
    }
}
