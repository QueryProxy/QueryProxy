<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AuditEvent;
use App\Enums\ChatProvider;
use App\Http\Controllers\Controller;
use App\Models\ChatIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Slack Events API (ADR-027). Only the Slack app's own signing secret is
 * accepted. The two events that matter end an installation: `app_uninstalled`
 * and `tokens_revoked` delete the stored bot token.
 */
class SlackEventsController extends Controller
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        abort_unless($request->attributes->get('slack_app_verified') === true, 401, 'Invalid Slack signature.');

        $body = $request->json()->all();

        if (($body['type'] ?? null) === 'url_verification') {
            $challenge = $body['challenge'] ?? null;

            return is_string($challenge)
                ? response()->json(['challenge' => $challenge])
                : response()->json(['text' => 'Unsupported payload.'], 400);
        }

        if (($body['type'] ?? null) !== 'event_callback') {
            return response('', 200);
        }

        $workspaceId = (string) ($body['team_id'] ?? '');
        $event = $body['event'] ?? [];

        match ($event['type'] ?? null) {
            'app_uninstalled' => $this->endInstallations($workspaceId, null, 'app_uninstalled'),
            // tokens.bot lists the bot users whose tokens were revoked; a user
            // token (tokens.oauth) does not touch the bot installation.
            'tokens_revoked' => $this->endInstallations($workspaceId, array_values(array_filter((array) ($event['tokens']['bot'] ?? []), 'is_string')), 'tokens_revoked'),
            default => null,
        };

        // Slack retries anything but a 2xx, including events we do not handle.
        return response('', 200);
    }

    /**
     * @param  list<string>|null  $botUserIds  null: every installation of the workspace; a list: those of these bot users
     */
    private function endInstallations(string $workspaceId, ?array $botUserIds, string $reason): void
    {
        if ($workspaceId === '' || $botUserIds === []) {
            return;
        }

        $integrations = ChatIntegration::query()
            ->where('provider', ChatProvider::Slack->value)
            ->where('slack_team_id', $workspaceId)
            ->when($botUserIds !== null, fn ($query) => $query->whereIn('bot_user_id', $botUserIds))
            ->get();

        foreach ($integrations as $integration) {
            // Idempotent: a removal that got there first (the ChatOps page, a
            // rejected token, a repeated event) already cleared and audited
            // this installation, so the locked re-read finds nothing to end.
            DB::transaction(function () use ($integration, $workspaceId, $botUserIds, $reason) {
                $current = ChatIntegration::query()->lockForUpdate()->find($integration->id);

                if (! $current
                    || $current->slack_team_id !== $workspaceId
                    || ($botUserIds !== null && ! in_array($current->bot_user_id, $botUserIds, true))) {
                    return;
                }

                $current->clearSlackInstallation();

                audit()->record(AuditEvent::SlackUninstalled, team: $current->team, metadata: [
                    'slack_team_id' => $workspaceId,
                    'reason' => $reason,
                ], system: true);
            });
        }
    }
}
