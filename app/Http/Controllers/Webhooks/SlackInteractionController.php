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

class SlackInteractionController extends Controller
{
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

        if (! preg_match('/^(approve|reject):(\d+)$/', $action, $matches)) {
            // Not an actionable button (e.g. the "Open in QueryProxy" link).
            return response()->json([]);
        }

        [, $verb, $requestId] = $matches;

        $queryRequest = QueryRequest::query()
            ->where('team_id', $integration->team_id)
            ->find((int) $requestId);

        if (! $queryRequest) {
            return $this->ephemeral('This query request no longer exists.');
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
