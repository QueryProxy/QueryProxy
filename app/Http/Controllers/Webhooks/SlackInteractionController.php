<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\ChatProvider;
use App\Http\Controllers\Controller;
use App\Models\ChatApprovalToken;
use App\Models\ChatIdentity;
use App\Models\ChatIntegration;
use App\Models\QueryRequest;
use App\Services\Approvals\ApprovalService;
use App\Services\Chat\SlackRequestForm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Slack Block Kit callback endpoint: the Approve / Reject buttons of a request
 * card (`block_actions`) and the `/queryproxy` modal (`view_submission`,
 * ADR-027).
 *
 * Two kinds of signature reach it. A request signed with a team's own signing
 * secret (incoming-webhook mode) names its integration; one signed with the
 * Slack app's signing secret only proves Slack sent it, and the integration is
 * found from the workspace in the payload and the team of the request.
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

    /** The control timed out but the request is still waiting: point at the newer card. */
    private const EXPIRED_ACTION = 'This approval action could not be verified: this button has expired. Use the button in the newer message, or open the request in QueryProxy.';

    public function __invoke(Request $request, ApprovalService $approvals, SlackRequestForm $form): JsonResponse|Response
    {
        $payload = json_decode((string) $request->input('payload'), true);

        if (is_array($payload) && ($payload['type'] ?? null) === 'view_submission') {
            return $this->viewSubmission($request, $payload, $form);
        }

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'block_actions') {
            return response()->json(['text' => 'Unsupported payload.'], 400);
        }

        /** @var ChatIntegration|null $integration null when the Slack app's signing secret signed the request */
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

        // Signed with the Slack app's secret: the integration is found below.
        $appSigned = $integration === null;

        $queryRequest = QueryRequest::query()
            ->when($integration, fn ($query) => $query->where('team_id', $integration->team_id))
            ->find((int) $requestId);

        if (! $queryRequest) {
            // An app-signed caller must not learn which request ids exist.
            return $this->ephemeral($appSigned ? self::UNVERIFIED_ACTION : 'This query request no longer exists.');
        }

        // Slack app: the workspace that sent the click must be the one the
        // request's team installed the app in ...
        $integration ??= ChatIntegration::query()
            ->enabledFor(ChatProvider::Slack)
            ->where('team_id', $queryRequest->team_id)
            ->where('slack_team_id', (string) ($payload['team']['id'] ?? ''))
            ->whereNotNull('bot_token')
            ->first();

        // ... or, for a team still in webhook mode whose own signing secret is
        // the app's (it also verified this request), that team's integration.
        // A team with an installation is bound to its workspace and gets no
        // such fallback.
        $integration ??= $request->attributes->get('slack_signed_integrations')
            ?->first(fn (ChatIntegration $candidate) => $candidate->team_id === $queryRequest->team_id && blank($candidate->bot_token));

        if ($integration === null) {
            return $this->ephemeral(self::UNVERIFIED_ACTION);
        }

        if (! ChatApprovalToken::isValidFor($queryRequest, $token)) {
            return $this->ephemeral(
                ChatApprovalToken::hasExpiredFor($queryRequest, $token) ? self::EXPIRED_ACTION : self::UNVERIFIED_ACTION,
            );
        }

        $identity = ChatIdentity::query()
            ->where('provider', 'slack')
            ->where('external_id', $slackUserId)
            // Suspended and soft-deleted users do not resolve: their chat identity is dead.
            ->whereHas('user', fn ($query) => $query->active())
            ->first();

        if (! $identity) {
            return $this->ephemeral(
                'Your Slack account is not linked to a QueryProxy user. '
                .'Ask an admin to set your Slack member ID in Teams & Users → Manage users.',
            );
        }

        $reviewer = $identity->user;

        // Defence in depth: the policy already requires the DBA role in this
        // team, but an identity mapped to an outsider must never even reach it.
        if (! $reviewer->belongsToTeam($integration->team)) {
            return $this->ephemeral('You are not allowed to decide on this request.');
        }

        // An override needs a written reason, and the card has no place to
        // type one. Nothing is applied and the token stays unspent: the
        // reviewer is sent to the web form, which collects the reason and
        // applies the override there.
        if ($verb === 'approve'
            && Gate::forUser($reviewer)->allows('review', $queryRequest)
            && $approvals->isOverride($queryRequest, $reviewer)) {
            return $this->ephemeral(
                'Approving this request would be an override, which needs a written reason. '
                .'Nothing was approved. Give the reason here: '.route('requests.override', $queryRequest),
            );
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

        // With the Slack app the card was already updated in place (chat.update)
        // by the approval; replying here as well would overwrite it with the
        // short text. Without a stored message, or in webhook mode, replace it.
        if ($integration->bot_token !== null && filled($queryRequest->slack_ts)) {
            return response()->json([]);
        }

        // Replace the original message so the buttons disappear.
        return response()->json([
            'replace_original' => true,
            'text' => $outcome,
        ]);
    }

    /**
     * The `/queryproxy` modal was submitted. Only the Slack app can reach it:
     * the modal is opened by the app's slash command.
     *
     * @param  array<string, mixed>  $payload
     */
    private function viewSubmission(Request $request, array $payload, SlackRequestForm $form): JsonResponse|Response
    {
        abort_unless($request->attributes->get('slack_app_verified') === true, 401, 'Invalid Slack signature.');

        if (($payload['view']['callback_id'] ?? null) !== SlackRequestForm::CALLBACK_ID) {
            return response()->json(['text' => 'Unsupported payload.'], 400);
        }

        $result = $form->submit($payload);

        // An empty body closes the modal.
        return $result === [] ? response('', 200) : response()->json($result);
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
