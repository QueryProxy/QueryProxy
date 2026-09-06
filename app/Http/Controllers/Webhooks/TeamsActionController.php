<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic HMAC-verified action endpoint for Microsoft Teams automations
 * (outgoing webhooks / Power Automate flows).
 *
 * Expected JSON body:
 *   { "action": "approve"|"reject", "request_id": 123,
 *     "actor_email": "dba@example.com", "reason": "optional for reject" }
 */
class TeamsActionController extends Controller
{
    public function __invoke(Request $request, ApprovalService $approvals): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'request_id' => ['required', 'integer'],
            'actor_email' => ['required', 'email'],
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

        $reviewer = User::where('email', $validated['actor_email'])->first();

        if (! $reviewer) {
            return response()->json(['ok' => false, 'message' => 'No QueryProxy user with this email.'], 422);
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

        return response()->json(['ok' => true, 'message' => $message]);
    }
}
