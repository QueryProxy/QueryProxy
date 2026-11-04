<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Chat\SlackApiException;
use App\Services\Chat\SlackClient;
use App\Services\Chat\SlackRequestForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The `/queryproxy` slash command (ADR-027): opens the request modal.
 *
 * Only the Slack app's own signing secret is accepted. The Slack member must
 * map to an active QueryProxy user through a ChatIdentity; anyone else gets an
 * ephemeral message and no modal.
 */
class SlackCommandController extends Controller
{
    public function __invoke(Request $request, SlackRequestForm $form, SlackClient $slack): JsonResponse|Response
    {
        abort_unless($request->attributes->get('slack_app_verified') === true, 401, 'Invalid Slack signature.');

        $integrations = $form->integrationsFor((string) $request->input('team_id'));

        if ($integrations->isEmpty()) {
            return $this->ephemeral('QueryProxy is not installed for this Slack workspace. Ask a DBA to install it.');
        }

        $user = $form->resolveUser((string) $request->input('user_id'));

        if ($user === null) {
            return $this->ephemeral(
                'Your Slack account is not linked to a QueryProxy user. '
                .'Ask an admin to set your Slack member ID in Teams & Users → Manage users.',
            );
        }

        $connections = $form->usableConnections($user, $integrations);

        if ($connections->isEmpty()) {
            return $this->ephemeral('You have no connection you can submit queries through.');
        }

        $triggerId = (string) $request->input('trigger_id');

        try {
            $slack->openView(
                $integrations->firstWhere('team_id', $connections->first()->team_id)->bot_token,
                $triggerId,
                $form->view($connections),
            );
        } catch (SlackApiException $e) {
            Log::warning('QueryProxy: opening the Slack request modal failed', ['error' => $e->slackError]);

            return $this->ephemeral('The request form could not be opened. Try again.');
        }

        return response('', 200);
    }

    private function ephemeral(string $text): JsonResponse
    {
        return response()->json(['response_type' => 'ephemeral', 'text' => $text]);
    }
}
